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
        if ($endpoint === '' || !str_starts_with($endpoint, 'https://') || $p256dh === '' || $auth === '') {
            throw new InvalidArgumentException('Invalid push subscription.');
        }
        $endpointBlindIndex = SensitiveData::blindIndex('push-endpoint', $endpoint);
        $now = current_utc();
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO push_subscriptions (endpoint, endpoint_cipher, endpoint_blind_index, p256dh, p256dh_cipher, auth, auth_cipher, user_agent, user_agent_cipher, active, created_at, updated_at)
             VALUES (:endpoint, :endpoint_cipher, :endpoint_blind_index, :p256dh, :p256dh_cipher, :auth, :auth_cipher, :user_agent, :user_agent_cipher, 1, :created_at, :updated_at)
             ON CONFLICT(endpoint_blind_index) DO UPDATE SET endpoint_cipher = excluded.endpoint_cipher, p256dh = excluded.p256dh, p256dh_cipher = excluded.p256dh_cipher, auth = excluded.auth, auth_cipher = excluded.auth_cipher, user_agent = excluded.user_agent, user_agent_cipher = excluded.user_agent_cipher, active = 1, updated_at = excluded.updated_at, last_error = NULL'
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
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return ['ok' => true, 'active' => true];
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
        $stmt->bindValue(':limit', max(1, min($limit, 10)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function notifyNewPublications(string $since): array
    {
        $papers = $this->newPublicationsSince($since, 5);
        $summary = ['subscriptions' => 0, 'sent' => 0, 'failed' => 0, 'expired' => 0, 'papers' => count($papers), 'messages' => []];
        if (!$papers) {
            $summary['messages'][] = 'No new publications to push.';
            return $summary;
        }
        $payload = $this->notificationPayload($papers);
        foreach ($this->activeSubscriptions() as $subscription) {
            $summary['subscriptions']++;
            try {
                $result = $this->send($subscription, $payload);
                if ($result['ok']) {
                    $summary['sent']++;
                    $this->markSent((int)$subscription['id']);
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
        $summary['messages'][] = 'Push sent to ' . $summary['sent'] . ' device(s) for ' . count($papers) . ' new ' . (count($papers) === 1 ? 'publication' : 'publications') . '.';
        return $summary;
    }

    public function send(array $subscription, array $payload): array
    {
        $subscription = $this->hydrateSubscription($subscription);
        $endpoint = (string)$subscription['endpoint'];
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
            ],
        ]);
        $response = @file_get_contents($endpoint, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match)) {
                $status = (int)$match[1];
                break;
            }
        }
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => mb_substr((string)$response, 0, 500)];
    }

    private function notificationPayload(array $papers): array
    {
        $first = $papers[0];
        $count = count($papers);
        return [
            'title' => $count === 1 ? 'New psilocybin research publication' : $count . ' new psilocybin research publications',
            'body' => $count === 1
                ? mb_substr((string)$first['title'], 0, 140)
                : mb_substr((string)$first['title'], 0, 110) . ' +' . ($count - 1) . ' more',
            'url' => Config::publicBaseUrl() . '#papers',
            'tag' => 'psilocybin-research-latest',
            'timestamp' => time() * 1000,
        ];
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
        $offset = 3;
        if (ord($der[1]) > 0x80) {
            $offset += ord($der[1]) - 0x80;
        }
        if (ord($der[$offset]) !== 0x02) {
            throw new RuntimeException('Invalid DER signature.');
        }
        $rLength = ord($der[++$offset]);
        $r = substr($der, ++$offset, $rLength);
        $offset += $rLength;
        if (ord($der[$offset]) !== 0x02) {
            throw new RuntimeException('Invalid DER signature.');
        }
        $sLength = ord($der[++$offset]);
        $s = substr($der, ++$offset, $sLength);
        return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT) . str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }

    private function markSent(int $id): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE push_subscriptions SET last_sent_at = :sent, last_error = NULL, updated_at = :updated WHERE id = :id');
        $now = current_utc();
        $stmt->execute(['sent' => $now, 'updated' => $now, 'id' => $id]);
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
