<?php
declare(strict_types=1);

final class PushService
{
    public function __construct(private Database $db, private PublicationRepository $publications)
    {
    }

    public function publicKey(): string
    {
        return Config::vapidPublicKey();
    }

    public function subscribe(array $payload, ?string $userAgent = null): array
    {
        $endpoint = trim((string)($payload['endpoint'] ?? ''));
        $p256dh = trim((string)($payload['keys']['p256dh'] ?? ''));
        $auth = trim((string)($payload['keys']['auth'] ?? ''));
        if ($endpoint === '' || !PushEndpoint::allowed($endpoint) || !PushEndpoint::validKeys($p256dh, $auth)) {
            throw new InvalidArgumentException('Invalid push subscription.');
        }
        $preferences = $this->normalizePreferences((array)($payload['preferences'] ?? []));
        $endpointBlindIndex = SensitiveData::blindIndex('push-endpoint', $endpoint);
        $now = current_utc();
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO push_subscriptions (endpoint, endpoint_cipher, endpoint_blind_index, p256dh, p256dh_cipher, auth, auth_cipher, user_agent, user_agent_cipher, active, frequency, substances, keywords, author, journal, topic, cited_doi, created_at, updated_at, last_checked_at)
             VALUES (:endpoint, :endpoint_cipher, :endpoint_blind_index, :p256dh, :p256dh_cipher, :auth, :auth_cipher, :user_agent, :user_agent_cipher, 1, :frequency, :substances, :keywords, :author, :journal, :topic, :cited_doi, :created_at, :updated_at, :last_checked_at)
             ON CONFLICT(endpoint_blind_index) DO UPDATE SET endpoint_cipher = excluded.endpoint_cipher, p256dh = excluded.p256dh, p256dh_cipher = excluded.p256dh_cipher, auth = excluded.auth, auth_cipher = excluded.auth_cipher, user_agent = excluded.user_agent, user_agent_cipher = excluded.user_agent_cipher, active = 1, frequency = excluded.frequency, substances = excluded.substances, keywords = excluded.keywords, author = excluded.author, journal = excluded.journal, topic = excluded.topic, cited_doi = excluded.cited_doi, updated_at = excluded.updated_at, last_checked_at = excluded.last_checked_at, last_sent_at = NULL, last_error = NULL'
        );
        $stmt->execute([
            'endpoint' => '[encrypted]',
            'endpoint_cipher' => SensitiveData::encrypt($endpoint),
            'endpoint_blind_index' => $endpointBlindIndex,
            'p256dh' => '[encrypted]',
            'p256dh_cipher' => SensitiveData::encrypt($p256dh),
            'auth' => '[encrypted]',
            'auth_cipher' => SensitiveData::encrypt($auth),
            'user_agent' => $userAgent === null ? null : '[encrypted]',
            'user_agent_cipher' => $userAgent === null ? null : SensitiveData::encrypt(mb_substr((string)$userAgent, 0, 500)),
            'frequency' => $preferences['frequency'],
            'substances' => $preferences['substances'],
            'keywords' => $preferences['keywords'],
            'author' => $preferences['author'],
            'journal' => $preferences['journal'],
            'topic' => $preferences['topic'],
            'cited_doi' => $preferences['cited_doi'],
            'created_at' => $now,
            'updated_at' => $now,
            'last_checked_at' => $now,
        ]);
        return ['ok' => true, 'active' => true, 'preferences' => $preferences];
    }

    public function unsubscribe(string $endpoint): bool
    {
        if (trim($endpoint) === '') {
            return false;
        }
        $stmt = $this->db->pdo()->prepare('UPDATE push_subscriptions SET active = 0, updated_at = :updated_at WHERE endpoint_blind_index = :endpoint_blind_index');
        $stmt->execute(['updated_at' => current_utc(), 'endpoint_blind_index' => SensitiveData::blindIndex('push-endpoint', trim($endpoint))]);
        return $stmt->rowCount() > 0;
    }

    public function preferences(array $payload): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM push_subscriptions WHERE endpoint_blind_index = :endpoint LIMIT 1');
        $stmt->execute(['endpoint' => SensitiveData::blindIndex('push-endpoint', (string)($payload['endpoint'] ?? ''))]);
        $row = $stmt->fetch();
        if (!$row) {
            return ['ok' => true, 'active' => false, 'preferences' => $this->normalizePreferences([])];
        }
        $row = $this->hydrateSubscription($row);
        if (!hash_equals((string)$row['auth'], (string)($payload['keys']['auth'] ?? ''))
            || !hash_equals((string)$row['p256dh'], (string)($payload['keys']['p256dh'] ?? ''))) {
            throw new InvalidArgumentException('Invalid push credentials.');
        }
        return ['ok' => true, 'active' => (bool)$row['active'], 'preferences' => array_intersect_key($row, array_flip(['frequency', 'substances', 'keywords', 'author', 'journal', 'topic', 'cited_doi']))];
    }

    public function activeSubscriptions(): array
    {
        return array_map(fn (array $row): array => $this->hydrateSubscription($row), $this->db->pdo()->query('SELECT * FROM push_subscriptions WHERE active = 1 ORDER BY created_at DESC')->fetchAll());
    }

    public function newPublicationsSince(string $since, int $limit = 5): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM publications
             WHERE hidden = 0 AND false_positive = 0 AND date_added >= :since
             ORDER BY publication_date DESC, id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':since', $since);
        $stmt->bindValue(':limit', max(1, min($limit, 100)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function notifyNewPublications(string $since): array
    {
        $until = current_utc();
        $summary = ['subscriptions' => 0, 'sent' => 0, 'failed' => 0, 'expired' => 0, 'papers' => 0, 'messages' => []];
        foreach ($this->activeSubscriptions() as $subscription) {
            $summary['subscriptions']++;
            if (!$this->isDue($subscription)) {
                continue;
            }
            $cursor = (string)($subscription['last_checked_at'] ?? $subscription['created_at'] ?? $since);
            $papers = $this->publications->recentForPush($subscription, $cursor, $until);
            $summary['papers'] += count($papers);
            if (!$papers) {
                $this->markChecked((int)$subscription['id'], $until);
                continue;
            }
            $payload = $this->notificationPayload($papers);
            try {
                $result = $this->send($subscription, $payload);
                if ($result['ok']) {
                    $summary['sent']++;
                    $this->markSent((int)$subscription['id'], $until);
                } elseif (in_array($result['status'], [404, 410], true)) {
                    $summary['expired']++;
                    $this->markFailed((int)$subscription['id'], 'Expired subscription: HTTP ' . $result['status'], false);
                } else {
                    $summary['failed']++;
                    $this->markFailed((int)$subscription['id'], 'HTTP ' . $result['status'] . ': ' . $result['body'], true);
                }
            } catch (Throwable $e) {
                $summary['failed']++;
                $this->markFailed((int)$subscription['id'], $e->getMessage(), true);
            }
        }
        $summary['messages'][] = 'Push sent to ' . $summary['sent'] . ' device(s) for ' . $summary['papers'] . ' matching new ' . ($summary['papers'] === 1 ? 'publication' : 'publications') . '.';
        return $summary;
    }

    public function send(array $subscription, array $payload): array
    {
        $subscription = $this->hydrateSubscription($subscription);
        $endpoint = (string)$subscription['endpoint'];
        if (!PushEndpoint::allowed($endpoint)) return ['ok' => false, 'status' => 0, 'body' => 'Unsupported push service endpoint.'];
        $audience = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $encrypted = $this->encryptPayload($body, (string)$subscription['p256dh'], (string)$subscription['auth']);
        $headers = [
            'TTL: 86400',
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'Authorization: vapid t=' . $this->vapidJwt((string)$audience) . ', k=' . Config::vapidPublicKey(),
        ];
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $encrypted,
                'ignore_errors' => true,
                'timeout' => 12,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
        ]);
        $response = @file_get_contents($endpoint, false, $context, 0, 8192);
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match)) {
                $status = (int)$match[1];
                break;
            }
        }
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => mb_substr((string)$response, 0, 500)];
    }

    private function notificationPayload(array $papers, ?int $total = null): array
    {
        $ids = array_values(array_unique(array_map(static fn(array $p): int => (int)$p['id'], $papers)));
        $batch = hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR));
        $stmt = $this->db->pdo()->prepare('INSERT OR IGNORE INTO push_notification_batches(id,paper_ids,created_at) VALUES(?,?,?)');
        $stmt->execute([$batch, json_encode($ids, JSON_THROW_ON_ERROR), current_utc()]);
        $count = count($ids);
        return [
            'title' => $count === 1 ? 'New psilocybin research publication' : $count . ' new psilocybin research publications',
            'body' => mb_substr((string)$papers[0]['title'], 0, $count === 1 ? 140 : 110) . ($count > 1 ? ' +' . ($count - 1) . ' more' : ''),
            'url' => $count === 1 ? Config::publicBaseUrl() . 'publication.php?id=' . $ids[0] . '&notification=' . $batch : Config::publicBaseUrl() . '?notification=' . $batch . '#papers',
            'batch' => $batch,
            'count' => $count,
            'kind' => 'research',
            'tag' => 'psilocybin-research-latest',
            'timestamp' => time() * 1000,
        ];
    }

    public function testNotification(array $payload): array
    {
        $saved = $this->preferences($payload);
        if (!$saved['active']) throw new InvalidArgumentException('Save push preferences before sending a test.');
        $index = SensitiveData::blindIndex('push-endpoint', (string)$payload['endpoint']);
        $stmt = $this->db->pdo()->prepare('INSERT INTO push_test_requests(endpoint_blind_index,requested_at) VALUES(:endpoint,:now) ON CONFLICT(endpoint_blind_index) DO UPDATE SET requested_at=excluded.requested_at WHERE requested_at < :cutoff');
        $stmt->execute(['endpoint'=>$index,'now'=>time(),'cutoff'=>time()-60]);
        if (!$stmt->rowCount()) throw new InvalidArgumentException('Please wait a minute before sending another test.');
        $result = $this->send(['endpoint'=>$payload['endpoint'],'auth'=>$payload['keys']['auth'],'p256dh'=>$payload['keys']['p256dh']], [
            'title'=>'Your research notifications are ready',
            'body'=>'Test notification for this device. Your phone settings control banners, lock screen, sound and vibration.',
            'kind'=>'test', 'tag'=>'psilocybin-research-test',
            'url'=>Config::publicBaseUrl() . '#notifications', 'timestamp'=>time()*1000,
        ]);
        return ['ok'=>$result['ok'], 'message'=>$result['ok'] ? 'Accepted by the push service. Check this device’s notifications.' : 'The push service could not accept the test. Try enabling push again.'];
    }

    private function encryptPayload(string $payload, string $userPublicKey, string $authSecret): string
    {
        $userPublic = Config::base64UrlDecode($userPublicKey);
        $auth = Config::base64UrlDecode($authSecret);
        if (strlen($userPublic) !== 65 || strlen($auth) < 16) {
            throw new InvalidArgumentException('Invalid push encryption keys.');
        }

        $local = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (!$local) {
            throw new RuntimeException('Unable to create push encryption key.');
        }
        $localDetails = openssl_pkey_get_details($local);
        $localPublic = "\x04" . $localDetails['ec']['x'] . $localDetails['ec']['y'];
        $peer = openssl_pkey_get_public($this->publicKeyPem($userPublic));
        if (!$peer) {
            throw new RuntimeException('Unable to read push subscription key.');
        }
        $shared = openssl_pkey_derive($peer, $local, 32);
        if (!is_string($shared) || strlen($shared) !== 32) {
            throw new RuntimeException('Unable to derive push shared secret.');
        }

        $salt = random_bytes(16);
        $prkKey = hash_hmac('sha256', $shared, $auth, true);
        $context = "WebPush: info\0" . $userPublic . $localPublic;
        $ikm = $this->hkdfExpand($prkKey, $context, 32);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = $this->hkdfExpand($prk, "Content-Encoding: aes128gcm\0", 16);
        $nonce = $this->hkdfExpand($prk, "Content-Encoding: nonce\0", 12);
        $ciphertext = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($ciphertext)) {
            throw new RuntimeException('Unable to encrypt push payload.');
        }
        return $salt . pack('N', 4096) . chr(strlen($localPublic)) . $localPublic . $ciphertext . $tag;
    }

    private function vapidJwt(string $audience): string
    {
        $keys = Config::vapidKeys();
        $header = Config::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']) ?: '{}');
        $claims = Config::base64UrlEncode(json_encode([
            'aud' => $audience,
            'exp' => time() + 3600,
            'sub' => Config::vapidSubject(),
        ], JSON_UNESCAPED_SLASHES) ?: '{}');
        $input = $header . '.' . $claims;
        $ok = openssl_sign($input, $signature, (string)$keys['private'], OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new RuntimeException('Unable to sign VAPID token.');
        }
        return $input . '.' . Config::base64UrlEncode($this->derToJose($signature));
    }

    private function publicKeyPem(string $point): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode((string)$der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function hkdfExpand(string $prk, string $info, int $length): string
    {
        $output = '';
        $previous = '';
        for ($i = 1; strlen($output) < $length; $i++) {
            $previous = hash_hmac('sha256', $previous . $info . chr($i), $prk, true);
            $output .= $previous;
        }
        return substr($output, 0, $length);
    }

    private function derToJose(string $der): string
    {
        $offset = 0;
        if ($der === '' || ord($der[$offset++]) !== 0x30) {
            throw new RuntimeException('Invalid DER signature.');
        }

        $sequenceLength = $this->readDerLength($der, $offset);
        if ($sequenceLength !== strlen($der) - $offset || $offset >= strlen($der) || ord($der[$offset++]) !== 0x02) {
            throw new RuntimeException('Invalid DER signature.');
        }

        $rLength = $this->readDerLength($der, $offset);
        $r = substr($der, $offset, $rLength);
        $offset += $rLength;
        if (strlen($r) !== $rLength || $offset >= strlen($der) || ord($der[$offset++]) !== 0x02) {
            throw new RuntimeException('Invalid DER signature.');
        }

        $sLength = $this->readDerLength($der, $offset);
        $s = substr($der, $offset, $sLength);
        $offset += $sLength;
        if (strlen($s) !== $sLength || $offset !== strlen($der)) {
            throw new RuntimeException('Invalid DER signature.');
        }

        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");
        if ($r === '' || $s === '' || strlen($r) > 32 || strlen($s) > 32) {
            throw new RuntimeException('Invalid DER signature.');
        }

        return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
    }

    private function readDerLength(string $der, int &$offset): int
    {
        if ($offset >= strlen($der)) {
            throw new RuntimeException('Invalid DER signature.');
        }

        $length = ord($der[$offset++]);
        if (($length & 0x80) === 0) {
            return $length;
        }

        $byteCount = $length & 0x7f;
        if ($byteCount < 1 || $byteCount > 4 || $offset + $byteCount > strlen($der)) {
            throw new RuntimeException('Invalid DER signature.');
        }

        $length = 0;
        for ($i = 0; $i < $byteCount; $i++) {
            $length = ($length << 8) | ord($der[$offset++]);
        }
        return $length;
    }

    private function markSent(int $id, ?string $until = null): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE push_subscriptions SET last_sent_at = :sent, last_checked_at = :checked, last_error = NULL, updated_at = :updated WHERE id = :id');
        $now = current_utc();
        $stmt->execute(['sent' => $now, 'checked' => $until ?? $now, 'updated' => $now, 'id' => $id]);
    }

    private function markChecked(int $id, string $until): void
    {
        $now = current_utc();
        $stmt = $this->db->pdo()->prepare('UPDATE push_subscriptions SET last_checked_at = :checked, updated_at = :updated WHERE id = :id');
        $stmt->execute(['checked' => $until, 'updated' => $now, 'id' => $id]);
    }

    private function normalizePreferences(array $preferences): array
    {
        $frequency = (string)($preferences['frequency'] ?? 'daily');
        if (!in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
            throw new InvalidArgumentException('Invalid push delivery frequency.');
        }
        $substances = array_values(array_intersect(array_map('strtolower', (array)($preferences['substances'] ?? [])), ['psilocybin', 'psilocin']));
        if (!$substances) {
            $substances = ['psilocybin', 'psilocin'];
        }
        return [
            'frequency' => $frequency,
            'substances' => implode(',', array_unique($substances)),
            'keywords' => trim((string)($preferences['keywords'] ?? '')) ?: null,
            'author' => trim((string)($preferences['author'] ?? '')) ?: null,
            'journal' => AlertFilters::encode($preferences['journal'] ?? null),
            'topic' => AlertFilters::encode($preferences['topic'] ?? null),
            'cited_doi' => normalize_doi((string)($preferences['cited_doi'] ?? '')),
        ];
    }

    private function isDue(array $subscription): bool
    {
        $seconds = match ((string)($subscription['frequency'] ?? 'daily')) {
            'weekly' => 7 * 86400,
            'monthly' => 30 * 86400,
            default => 86400,
        };
        $anchor = (string)(($subscription['last_checked_at'] ?? null) ?: ($subscription['last_sent_at'] ?? null) ?: ($subscription['created_at'] ?? ''));
        $timestamp = strtotime($anchor . ' UTC');
        return $timestamp === false || (time() - $timestamp) >= $seconds;
    }


    private function markFailed(int $id, string $message, bool $keepActive): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE push_subscriptions SET active = :active, last_error = :error, updated_at = :updated WHERE id = :id');
        $stmt->execute(['active' => $keepActive ? 1 : 0, 'error' => mb_substr($message, 0, 500), 'updated' => current_utc(), 'id' => $id]);
    }

    private function hydrateSubscription(array $row): array
    {
        foreach (['endpoint', 'p256dh', 'auth', 'user_agent'] as $field) {
            $cipherField = $field . '_cipher';
            if (!empty($row[$cipherField])) {
                $row[$field] = SensitiveData::decrypt((string)$row[$cipherField]);
            }
        }
        return $row;
    }
}
