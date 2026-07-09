<?php
declare(strict_types=1);

final class AlertService
{
    private const LOGO_CID = 'psilocybin-research-logo';

    public function __construct(private Database $db, private PublicationRepository $publications)
    {
    }

    public function subscribe(string $email, string $frequency, array $substances, ?string $keywords, ?string $author = null, ?string $journal = null, ?string $topic = null, ?string $citedDoi = null): array
    {
        $email = filter_var(SensitiveData::canonicalEmail($email), FILTER_VALIDATE_EMAIL);
        if (!$email) {
            throw new InvalidArgumentException('Enter a valid email address.');
        }
        if (!in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
            throw new InvalidArgumentException('Invalid alert frequency.');
        }
        $substances = array_values(array_intersect($substances, ['psilocybin', 'psilocin']));
        if (!$substances) {
            $substances = ['psilocybin', 'psilocin'];
        }
        $keywords = trim((string)$keywords) ?: null;
        $author = trim((string)$author) ?: null;
        $journal = trim((string)$journal) ?: null;
        $topic = trim((string)$topic) ?: null;
        $citedDoi = normalize_doi($citedDoi);
        $now = current_utc();
        $token = bin2hex(random_bytes(18));
        $confirmationToken = bin2hex(random_bytes(24));
        $publicUuid = self::uuidV4();

        $params = [
            'email_blind_index' => SensitiveData::blindIndex('alert-email', $email),
            'frequency' => $frequency,
            'substances' => implode(',', $substances),
            'keywords' => $keywords,
            'author' => $author,
            'journal' => $journal,
            'topic' => $topic,
            'cited_doi' => $citedDoi,
        ];
        $existingStmt = $this->db->pdo()->prepare(
            'SELECT * FROM alert_subscriptions
             WHERE email_blind_index = :email_blind_index
               AND frequency = :frequency
               AND substances = :substances
               AND COALESCE(keywords, \'\') = COALESCE(:keywords, \'\')
               AND COALESCE(author, \'\') = COALESCE(:author, \'\')
               AND COALESCE(journal, \'\') = COALESCE(:journal, \'\')
               AND COALESCE(topic, \'\') = COALESCE(:topic, \'\')
               AND COALESCE(cited_doi, \'\') = COALESCE(:cited_doi, \'\')
             LIMIT 1'
        );
        $existingStmt->execute($params);
        $existing = $existingStmt->fetch();

        if ($existing && (int)$existing['active'] === 1 && !empty($existing['confirmed_at'])) {
            return $this->hydrateSubscription($existing);
        }

        if ($existing) {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE alert_subscriptions
                 SET active = 0, confirmed_at = NULL, confirmation_token = :confirmation_token, confirmation_token_cipher = :confirmation_token_cipher, confirmation_token_blind_index = :confirmation_token_blind_index, confirmation_sent_at = NULL, updated_at = :updated
                 WHERE id = :id'
            );
            $stmt->execute([
                'confirmation_token' => '[encrypted]',
                'confirmation_token_cipher' => SensitiveData::encrypt($confirmationToken),
                'confirmation_token_blind_index' => SensitiveData::blindIndex('alert-confirmation-token', $confirmationToken),
                'updated' => $now,
                'id' => (int)$existing['id'],
            ]);
            return $this->findById((int)$existing['id']) ?: [];
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO alert_subscriptions (public_uuid, email, email_cipher, email_blind_index, frequency, substances, keywords, author, journal, topic, cited_doi, active, token, token_cipher, token_blind_index, confirmation_token, confirmation_token_cipher, confirmation_token_blind_index, created_at, updated_at)
             VALUES (:public_uuid, :email, :email_cipher, :email_blind_index, :frequency, :substances, :keywords, :author, :journal, :topic, :cited_doi, 0, :token, :token_cipher, :token_blind_index, :confirmation_token, :confirmation_token_cipher, :confirmation_token_blind_index, :created, :updated)'
        );
        $stmt->execute([
            'public_uuid' => $publicUuid,
            'email' => '[encrypted]',
            'email_cipher' => SensitiveData::encrypt($email),
            'email_blind_index' => $params['email_blind_index'],
            'frequency' => $frequency,
            'substances' => implode(',', $substances),
            'keywords' => $keywords,
            'author' => $author,
            'journal' => $journal,
            'topic' => $topic,
            'cited_doi' => $citedDoi,
            'token' => '[encrypted]',
            'token_cipher' => SensitiveData::encrypt($token),
            'token_blind_index' => SensitiveData::blindIndex('alert-token', $token),
            'confirmation_token' => '[encrypted]',
            'confirmation_token_cipher' => SensitiveData::encrypt($confirmationToken),
            'confirmation_token_blind_index' => SensitiveData::blindIndex('alert-confirmation-token', $confirmationToken),
            'created' => $now,
            'updated' => $now,
        ]);

        return $this->findById((int)$this->db->pdo()->lastInsertId()) ?: [];
    }

    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT * FROM alert_subscriptions WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrateSubscription($row) : null;
    }

    public function findByToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !preg_match('/^[a-f0-9]{24,80}$/i', $token)) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT * FROM alert_subscriptions WHERE token_blind_index = :token LIMIT 1');
        $stmt->execute(['token' => SensitiveData::blindIndex('alert-token', $token)]);
        $row = $stmt->fetch();
        return $row ? $this->hydrateSubscription($row) : null;
    }

    public function findByConfirmationToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || !preg_match('/^[a-f0-9]{24,96}$/i', $token)) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('SELECT * FROM alert_subscriptions WHERE confirmation_token_blind_index = :token LIMIT 1');
        $stmt->execute(['token' => SensitiveData::blindIndex('alert-confirmation-token', $token)]);
        $row = $stmt->fetch();
        return $row ? $this->hydrateSubscription($row) : null;
    }

    public function confirm(string $confirmationToken): ?array
    {
        $subscription = $this->findByConfirmationToken($confirmationToken);
        if (!$subscription) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare(
            'UPDATE alert_subscriptions
             SET active = 1, confirmed_at = :confirmed, confirmation_token = NULL, confirmation_token_cipher = NULL, confirmation_token_blind_index = NULL, updated_at = :updated
             WHERE id = :id'
        );
        $now = current_utc();
        $stmt->execute([
            'confirmed' => $now,
            'updated' => $now,
            'id' => (int)$subscription['id'],
        ]);
        return $this->findById((int)$subscription['id']);
    }

    public function updatePreferences(string $token, string $frequency, array $substances, ?string $keywords, ?string $author = null, ?string $journal = null, ?string $topic = null, ?string $citedDoi = null): array
    {
        $subscription = $this->findByToken($token);
        if (!$subscription) {
            throw new InvalidArgumentException('Alert subscription not found.');
        }
        if (!in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
            throw new InvalidArgumentException('Invalid alert frequency.');
        }
        $substances = array_values(array_intersect($substances, ['psilocybin', 'psilocin']));
        if (!$substances) {
            $substances = ['psilocybin', 'psilocin'];
        }
        $stmt = $this->db->pdo()->prepare(
            'UPDATE alert_subscriptions
             SET frequency = :frequency, substances = :substances, keywords = :keywords, author = :author, journal = :journal, topic = :topic, cited_doi = :cited_doi, active = CASE WHEN confirmed_at IS NULL THEN 0 ELSE active END, updated_at = :updated
             WHERE token_blind_index = :token'
        );
        $stmt->execute([
            'frequency' => $frequency,
            'substances' => implode(',', $substances),
            'keywords' => trim((string)$keywords) ?: null,
            'author' => trim((string)$author) ?: null,
            'journal' => trim((string)$journal) ?: null,
            'topic' => trim((string)$topic) ?: null,
            'cited_doi' => normalize_doi($citedDoi),
            'updated' => current_utc(),
            'token' => SensitiveData::blindIndex('alert-token', $token),
        ]);
        return $this->findByToken($token) ?: [];
    }

    public function pause(string $token): bool
    {
        $subscription = $this->findByToken($token);
        if (!$subscription) {
            return false;
        }
        $stmt = $this->db->pdo()->prepare('UPDATE alert_subscriptions SET active = 0, updated_at = :updated WHERE token_blind_index = :token');
        $stmt->execute(['updated' => current_utc(), 'token' => SensitiveData::blindIndex('alert-token', $token)]);
        return true;
    }

    public function resume(string $token): ?array
    {
        $subscription = $this->findByToken($token);
        if (!$subscription || empty($subscription['confirmed_at'])) {
            return null;
        }
        $stmt = $this->db->pdo()->prepare('UPDATE alert_subscriptions SET active = 1, updated_at = :updated WHERE token_blind_index = :token');
        $stmt->execute(['updated' => current_utc(), 'token' => SensitiveData::blindIndex('alert-token', $token)]);
        return $this->findByToken($token);
    }

    public function unsubscribe(string $token): bool
    {
        return $this->pause($token);
    }

    public function unenrol(string $token): bool
    {
        return $this->unsubscribe($token);
    }

    public function deleteSubscription(string $token): bool
    {
        $subscription = $this->findByToken($token);
        if (!$subscription) {
            return false;
        }
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('DELETE FROM alert_deliveries WHERE subscription_id = :id');
            $stmt->execute(['id' => (int)$subscription['id']]);
            $stmt = $pdo->prepare('DELETE FROM alert_subscriptions WHERE id = :id');
            $stmt->execute(['id' => (int)$subscription['id']]);
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function subscriptions(): array
    {
        return array_map(fn (array $row): array => $this->hydrateSubscription($row), $this->db->pdo()->query('SELECT * FROM alert_subscriptions WHERE active = 1 AND confirmed_at IS NOT NULL ORDER BY created_at DESC')->fetchAll());
    }

    public function generateDue(string $frequency = 'daily', bool $mark = true): array
    {
        if (!in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
            throw new InvalidArgumentException('Invalid alert frequency.');
        }
        $since = match ($frequency) {
            'monthly' => gmdate('Y-m-d', strtotime('-1 month')),
            'weekly' => gmdate('Y-m-d', strtotime('-7 days')),
            default => gmdate('Y-m-d', strtotime('-1 day')),
        };
        $stmt = $this->db->pdo()->prepare('SELECT * FROM alert_subscriptions WHERE active = 1 AND confirmed_at IS NOT NULL AND frequency = :frequency ORDER BY created_at');
        $stmt->execute(['frequency' => $frequency]);
        $digests = [];
        foreach ($stmt->fetchAll() as $row) {
            $subscription = $this->hydrateSubscription($row);
            $papers = $this->publications->recentForAlert($subscription, $since);
            $new = [];
            foreach ($papers as $paper) {
                if (!$this->alreadyDelivered((int)$subscription['id'], (int)$paper['id'], $frequency)) {
                    $new[] = $paper;
                }
            }
            if (!$new) {
                continue;
            }
            if ($mark) {
                foreach ($new as $paper) {
                    $this->markDelivered((int)$subscription['id'], (int)$paper['id'], $frequency);
                }
            }
            $digests[] = [
                'subscription' => $subscription,
                'papers' => $new,
                'subject' => $this->subjectLine($subscription, count($new)),
                'body' => $this->renderTextDigest($subscription, $new),
                'text' => $this->renderTextDigest($subscription, $new),
                'html' => $this->renderHtmlDigest($subscription, $new),
                'headers' => $this->emailHeaders($subscription),
                'attachments' => $this->embeddedAttachments(),
            ];
        }
        return $digests;
    }

    public function deliverDue(string $frequency = 'daily'): array
    {
        $summary = ['generated' => 0, 'sent' => 0, 'failed' => 0, 'messages' => []];
        foreach ($this->generateDue($frequency, false) as $digest) {
            $summary['generated']++;
            if ($this->sendDigest($digest)) {
                $this->markDigestDelivered($digest);
                $summary['sent']++;
                $publicationCount = count($digest['papers'] ?? []);
                $summary['messages'][] = 'Sent alert to ' . ($digest['subscription']['email'] ?? 'unknown recipient') . ' for ' . $publicationCount . ' ' . ($publicationCount === 1 ? 'publication' : 'publications') . '.';
            } else {
                $summary['failed']++;
                $summary['messages'][] = 'Failed to send alert to ' . ($digest['subscription']['email'] ?? 'unknown recipient') . '.';
            }
        }
        return $summary;
    }

    public function sendDigest(array $digest): bool
    {
        $message = $this->buildMailMessage($digest);
        if (trim((string)$message['to']) === '') {
            return false;
        }
        return $this->sendMail($message);
    }

    public function markDigestDelivered(array $digest): void
    {
        $subscriptionId = (int)($digest['subscription']['id'] ?? 0);
        $frequency = (string)($digest['subscription']['frequency'] ?? 'daily');
        if ($subscriptionId <= 0) {
            return;
        }
        foreach (($digest['papers'] ?? []) as $paper) {
            $this->markDelivered($subscriptionId, (int)$paper['id'], $frequency);
        }
    }

    public function renderDigest(array $subscription, array $papers): string
    {
        return $this->renderTextDigest($subscription, $papers);
    }

    public function renderTextDigest(array $subscription, array $papers): string
    {
        $lines = [];
        $manageUrl = $this->manageUrl($subscription);
        $unsubscribeUrl = $this->unsubscribeUrl($subscription);
        $dataProtectionUrl = Config::publicBaseUrl() . 'data-protection.php';
        $lines[] = 'To: ' . $subscription['email'];
        $lines[] = 'Subject: ' . $this->subjectLine($subscription, count($papers));
        $lines[] = '';
        $publicationCount = count($papers);
        $publicationLabel = $publicationCount === 1 ? 'publication' : 'publications';
        $lines[] = $publicationCount . ' new ' . $publicationLabel . ' matched your ' . $subscription['frequency'] . ' alert.';
        $lines[] = 'Alert UUID: ' . (string)($subscription['public_uuid'] ?? 'unavailable');
        $lines[] = 'Preferences: ' . $this->preferenceSummary($subscription);
        $lines[] = '';
        foreach ($papers as $i => $paper) {
            $lines[] = ($i + 1) . '. ' . $paper['title'];
            $lines[] = '   ' . trim(($paper['journal'] ?: 'Unknown journal') . ' | ' . ($paper['publication_date'] ?: 'Unknown date'));
            if (!empty($paper['doi'])) {
                $lines[] = '   DOI: ' . $paper['doi'];
            }
            if (!empty($paper['pubmed_id'])) {
                $lines[] = '   PubMed: ' . $paper['pubmed_id'];
            }
            if (!empty($paper['source_url'])) {
                $lines[] = '   URL: ' . $paper['source_url'];
            }
            $lines[] = '';
        }
        $lines[] = 'Manage alert preferences: ' . $manageUrl;
        $lines[] = 'Unsubscribe from this alert: ' . $unsubscribeUrl;
        $lines[] = 'Data protection notice: ' . $dataProtectionUrl;
        $lines[] = '';
        $lines[] = 'Data protection notice: This alert uses your email address and selected research filters only to send publication updates you requested. Email addresses and alert access tokens are encrypted at rest. You can change, stop, or delete the alert at any time. No tracking pixel is included.';
        return implode("\n", $lines);
    }

    public function renderHtmlDigest(array $subscription, array $papers): string
    {
        $manageUrl = $this->manageUrl($subscription);
        $unsubscribeUrl = $this->unsubscribeUrl($subscription);
        $dataProtectionUrl = Config::publicBaseUrl() . 'data-protection.php';
        $rows = '';
        foreach ($papers as $paper) {
            $title = self::e((string)$paper['title']);
            $journal = self::e((string)($paper['journal'] ?: 'Unknown journal'));
            $date = self::e((string)($paper['publication_date'] ?: 'Unknown date'));
            $authors = self::e((string)($paper['authors'] ?: 'Authors unavailable'));
            $source = self::e((string)($paper['source_url'] ?: Config::publicBaseUrl()));
            $doi = !empty($paper['doi']) ? '<a href="https://doi.org/' . self::e((string)$paper['doi']) . '" style="color:#1a6b54;text-decoration:none;font-weight:800;">DOI ' . self::e((string)$paper['doi']) . '</a>' : '';
            $pubmed = !empty($paper['pubmed_id']) ? '<a href="https://pubmed.ncbi.nlm.nih.gov/' . self::e((string)$paper['pubmed_id']) . '/" style="color:#1a6b54;text-decoration:none;font-weight:800;">PubMed ' . self::e((string)$paper['pubmed_id']) . '</a>' : '';
            $links = trim($doi . ($doi && $pubmed ? ' <span style="color:#bdb6a5;">|</span> ' : '') . $pubmed);
            $rows .= '<tr><td style="padding:18px 0;border-top:1px solid #d8d2c4;">'
                . '<a href="' . $source . '" style="display:block;color:#24251f;text-decoration:none;font-size:18px;line-height:1.28;font-weight:850;">' . $title . '</a>'
                . '<div style="margin-top:7px;color:#686c61;font-size:13px;line-height:1.45;">' . $journal . ' &middot; ' . $date . '</div>'
                . '<div style="margin-top:5px;color:#686c61;font-size:13px;line-height:1.45;">' . $authors . '</div>'
                . ($links ? '<div style="margin-top:10px;font-size:13px;">' . $links . '</div>' : '')
                . '</td></tr>';
        }

        $alertUuid = self::e((string)($subscription['public_uuid'] ?? 'unavailable'));
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;background:#ffffff;color:#24251f;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;">'
            . '<div style="display:none;max-height:0;overflow:hidden;">' . count($papers) . ' new ' . (count($papers) === 1 ? 'publication' : 'publications') . ' matched your psilocybin research alert.</div>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#ffffff;"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:720px;background:#ffffff;border:1px solid #d8d2c4;border-radius:8px;overflow:hidden;box-shadow:0 1px 2px rgba(36,37,31,.08);">'
            . '<tr><td style="background:#ffffff;padding:18px 24px;border-bottom:1px solid #d8d2c4;border-left:4px solid #123c31;">'
            . '<table role="presentation" cellspacing="0" cellpadding="0"><tr>'
            . '<td style="width:54px;padding-right:13px;"><img src="cid:' . self::LOGO_CID . '" width="46" height="46" alt="" style="display:block;width:46px;height:46px;border:0;border-radius:7px;"></td>'
            . '<td><div style="color:#24251f;font-size:18px;font-weight:850;line-height:1.1;">Psilocybin-Research.com</div><div style="margin-top:3px;color:#1a6b54;font-size:12px;font-weight:760;line-height:1.2;">Searchable psilocybin and psilocin bibliometric database.</div></td>'
            . '</tr></table>'
            . '</td></tr>'
            . '<tr><td style="padding:26px 24px 8px;">'
            . '<h1 style="margin:0;color:#24251f;font-size:26px;line-height:1.16;">Publication alert</h1>'
            . '<p style="margin:8px 0 0;color:#686c61;font-size:15px;line-height:1.5;">' . count($papers) . ' new ' . (count($papers) === 1 ? 'publication' : 'publications') . ' matched your ' . self::e((string)$subscription['frequency']) . ' alert.</p>'
            . '<div style="margin:16px 0 0;padding:12px 14px;border:1px solid #d8d2c4;border-radius:7px;background:#f7f6f1;color:#686c61;font-size:13px;line-height:1.55;">'
            . '<div><strong style="color:#24251f;">Alert UUID:</strong> <span style="font-family:Consolas,Menlo,monospace;color:#24251f;">' . $alertUuid . '</span></div>'
            . '<div style="margin-top:5px;"><strong style="color:#24251f;">Research filters:</strong> ' . self::e($this->preferenceSummary($subscription)) . '</div>'
            . '</div>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-top:16px;">' . $rows . '</table>'
            . '</td></tr>'
            . '<tr><td style="padding:0 24px 24px;">'
            . '<table role="presentation" cellspacing="0" cellpadding="0"><tr>'
            . '<td style="padding:0 10px 10px 0;"><a href="' . self::e($manageUrl) . '" style="display:inline-block;background:#123c31;color:#ffffff;text-decoration:none;border:1px solid #123c31;border-radius:6px;padding:11px 15px;font-size:13px;font-weight:800;">Manage preferences</a></td>'
            . '<td style="padding:0 0 10px;"><a href="' . self::e($unsubscribeUrl) . '" style="display:inline-block;background:#ffffff;color:#1a6b54;text-decoration:none;border:1px solid #bdb6a5;border-radius:6px;padding:10px 14px;font-size:13px;font-weight:800;">Unsubscribe</a></td>'
            . '</tr></table>'
            . '<div style="margin-top:12px;padding:14px;border:1px solid #d8d2c4;border-radius:6px;background:#f7f6f1;color:#686c61;font-size:12px;line-height:1.55;">'
            . '<strong style="color:#24251f;">Data protection notice.</strong> This alert uses your email address and selected filters only to send publication updates you requested. Email addresses and alert access tokens are encrypted at rest. The email contains no tracking pixel. You can manage, stop, or delete this alert at any time using the links above. <a href="' . self::e($dataProtectionUrl) . '" style="color:#1a6b54;font-weight:800;text-decoration:none;">Read the full notice</a>.'
            . '</div>'
            . '</td></tr>'
            . '<tr><td style="padding:14px 24px;background:#f7f6f1;border-top:1px solid #d8d2c4;color:#686c61;font-size:11px;line-height:1.5;">'
            . 'Psilocybin-Research.com Publication Tracker. Coverage may be incomplete; verify records before citation or clinical use.'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    public function sendConfirmation(array $subscription): bool
    {
        if (empty($subscription['email']) || empty($subscription['confirmation_token'])) {
            return false;
        }
        $message = $this->buildMailMessage($this->confirmationDigest($subscription));
        if (trim((string)$message['to']) === '') {
            return false;
        }
        $sent = $this->sendMail($message);
        if ($sent) {
            $stmt = $this->db->pdo()->prepare('UPDATE alert_subscriptions SET confirmation_sent_at = :sent, updated_at = :updated WHERE id = :id');
            $now = current_utc();
            $stmt->execute(['sent' => $now, 'updated' => $now, 'id' => (int)$subscription['id']]);
        }
        return $sent;
    }

    private function sendMail(array $message): bool
    {
        $sent = @mail((string)$message['to'], (string)$message['subject'], (string)$message['body'], implode("\r\n", (array)$message['headers']));
        if (!$sent) {
            OperationalLogger::warning('alert.mail.failed', [
                'recipient_hash' => hash('sha256', (string)$message['to']),
                'subject' => (string)$message['subject'],
            ]);
        }
        return $sent;
    }

    public function confirmationDigest(array $subscription): array
    {
        return [
            'subscription' => $subscription,
            'subject' => 'Confirm your Psilocybin Research publication alert',
            'body' => $this->renderConfirmationText($subscription),
            'text' => $this->renderConfirmationText($subscription),
            'html' => $this->renderConfirmationHtml($subscription),
            'headers' => $this->confirmationHeaders($subscription),
            'attachments' => $this->embeddedAttachments(),
        ];
    }

    public function confirmationUrl(array $subscription): string
    {
        return Config::publicBaseUrl() . 'alert.php?action=confirm&confirm=' . rawurlencode((string)($subscription['confirmation_token'] ?? ''));
    }

    public function renderConfirmationText(array $subscription): string
    {
        $lines = [];
        $lines[] = 'Confirm your publication alert';
        $lines[] = '';
        $lines[] = 'Please confirm that you requested this Psilocybin-Research.com publication alert.';
        $lines[] = 'Alert UUID: ' . (string)($subscription['public_uuid'] ?? 'unavailable');
        $lines[] = 'Alert filters: ' . $this->preferenceSummary($subscription);
        $lines[] = '';
        $lines[] = 'Confirm this alert: ' . $this->confirmationUrl($subscription);
        $lines[] = 'Review or adjust preferences: ' . $this->manageUrl($subscription);
        $lines[] = 'Data protection notice: ' . Config::publicBaseUrl() . 'data-protection.php';
        $lines[] = '';
        $lines[] = 'You can adjust the alert filters before or after confirmation. If you did not request this alert, ignore this email. No publication digests will be sent unless the confirmation link is opened.';
        $lines[] = '';
        $lines[] = 'Data protection notice: We store your email address and selected research filters only after this request so you can confirm or ignore the alert. Email addresses and alert access tokens are encrypted at rest. No tracking pixel is included.';
        return implode("\n", $lines);
    }

    public function renderConfirmationHtml(array $subscription): string
    {
        $confirmUrl = self::e($this->confirmationUrl($subscription));
        $manageUrl = self::e($this->manageUrl($subscription));
        $dataProtectionUrl = self::e(Config::publicBaseUrl() . 'data-protection.php');
        $alertUuid = self::e((string)($subscription['public_uuid'] ?? 'unavailable'));
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;background:#ffffff;color:#24251f;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;">'
            . '<div style="display:none;max-height:0;overflow:hidden;">Confirm your Psilocybin Research publication alert.</div>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#ffffff;"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:680px;background:#ffffff;border:1px solid #d8d2c4;border-radius:8px;overflow:hidden;box-shadow:0 1px 2px rgba(36,37,31,.08);">'
            . '<tr><td style="background:#ffffff;padding:18px 24px;border-bottom:1px solid #d8d2c4;border-left:4px solid #123c31;">'
            . '<table role="presentation" cellspacing="0" cellpadding="0"><tr>'
            . '<td style="width:54px;padding-right:13px;"><img src="cid:' . self::LOGO_CID . '" width="46" height="46" alt="" style="display:block;width:46px;height:46px;border:0;border-radius:7px;"></td>'
            . '<td><div style="color:#24251f;font-size:18px;font-weight:850;line-height:1.1;">Psilocybin-Research.com</div><div style="margin-top:3px;color:#1a6b54;font-size:12px;font-weight:760;line-height:1.2;">Searchable psilocybin and psilocin bibliometric database.</div></td>'
            . '</tr></table>'
            . '</td></tr>'
            . '<tr><td style="padding:26px 24px;">'
            . '<h1 style="margin:0;color:#24251f;font-size:25px;line-height:1.18;">Confirm your publication alert</h1>'
            . '<p style="margin:10px 0 0;color:#686c61;font-size:15px;line-height:1.55;">Please confirm that you requested email updates for newly indexed psilocybin and psilocin literature.</p>'
            . '<div style="margin:16px 0 0;padding:12px 14px;border:1px solid #d8d2c4;border-radius:7px;background:#f7f6f1;color:#686c61;font-size:13px;line-height:1.55;">'
            . '<div><strong style="color:#24251f;">Alert UUID:</strong> <span style="font-family:Consolas,Menlo,monospace;color:#24251f;">' . $alertUuid . '</span></div>'
            . '<div style="margin-top:5px;"><strong style="color:#24251f;">Research filters:</strong> ' . self::e($this->preferenceSummary($subscription)) . '</div>'
            . '</div>'
            . '<p style="margin:22px 0;"><a href="' . $confirmUrl . '" style="display:inline-block;background:#123c31;color:#ffffff;text-decoration:none;border:1px solid #123c31;border-radius:6px;padding:12px 18px;font-size:14px;font-weight:800;">Confirm alert</a></p>'
            . '<p style="margin:0 0 14px;color:#686c61;font-size:13px;line-height:1.5;">Need to change the filters? <a href="' . $manageUrl . '" style="color:#1a6b54;font-weight:800;text-decoration:none;">Review or adjust preferences</a>.</p>'
            . '<div style="margin-top:18px;padding:14px;border:1px solid #d8d2c4;border-radius:6px;background:#f7f6f1;color:#686c61;font-size:12px;line-height:1.55;">'
            . '<strong style="color:#24251f;">Data protection notice.</strong> No publication digests will be sent unless this confirmation link is opened. If you did not request this alert, ignore this email. Email addresses and alert access tokens are encrypted at rest. The template contains no tracking pixel. <a href="' . $dataProtectionUrl . '" style="color:#1a6b54;font-weight:800;text-decoration:none;">Read the full notice</a>.'
            . '</div>'
            . '</td></tr>'
            . '<tr><td style="padding:14px 24px;background:#f7f6f1;border-top:1px solid #d8d2c4;color:#686c61;font-size:11px;line-height:1.5;">'
            . 'Psilocybin-Research.com Publication Tracker. Coverage may be incomplete; verify records before citation or clinical use.'
            . '</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    public function confirmationHeaders(array $subscription): array
    {
        return [
            'MIME-Version' => '1.0',
            'From' => $this->formatAddress(Config::alertFromEmail(), Config::alertFromName()),
            'Reply-To' => Config::alertFromEmail(),
            'X-Alert-Confirm-URL' => $this->confirmationUrl($subscription),
            'X-Alert-UUID' => (string)($subscription['public_uuid'] ?? ''),
        ];
    }

    public function emailHeaders(array $subscription): array
    {
        $unsubscribeUrl = $this->unsubscribeUrl($subscription);
        $manageUrl = $this->manageUrl($subscription);
        return [
            'MIME-Version' => '1.0',
            'From' => $this->formatAddress(Config::alertFromEmail(), Config::alertFromName()),
            'Reply-To' => Config::alertFromEmail(),
            'List-Unsubscribe' => '<' . $unsubscribeUrl . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            'X-Alert-Manage-URL' => $manageUrl,
            'X-Alert-UUID' => (string)($subscription['public_uuid'] ?? ''),
        ];
    }

    public function embeddedAttachments(): array
    {
        $path = Config::baseDir() . '/assets/mushroom-brand-mark.webp';
        return [[
            'path' => $path,
            'content_id' => self::LOGO_CID,
            'filename' => 'psilocybin-research-mushroom.webp',
            'content_type' => 'image/webp',
            'disposition' => 'inline',
        ]];
    }

    public function renderMimeMessage(array $digest): string
    {
        $message = $this->buildMailMessage($digest);
        $lines = [];
        $lines[] = 'To: ' . $message['to'];
        $lines[] = 'Subject: ' . $message['subject'];
        foreach ($message['headers'] as $header) {
            $lines[] = $header;
        }
        $lines[] = '';
        $lines[] = $message['body'];
        return implode("\r\n", $lines);
    }

    public function buildMailMessage(array $digest): array
    {
        $subscription = $digest['subscription'] ?? [];
        $subject = (string)($digest['subject'] ?? ('New psilocybin & psilocin publications (' . gmdate('Y-m-d') . ')'));
        $text = (string)($digest['text'] ?? $digest['body'] ?? '');
        $html = (string)($digest['html'] ?? '');
        $headers = (array)($digest['headers'] ?? $this->emailHeaders($subscription));
        $attachments = (array)($digest['attachments'] ?? $this->embeddedAttachments());
        $relatedBoundary = 'related_' . bin2hex(random_bytes(8));
        $altBoundary = 'alt_' . bin2hex(random_bytes(8));
        $headerLines = [];
        foreach ($headers as $name => $value) {
            if ($name === 'MIME-Version') {
                continue;
            }
            $headerLines[] = $name . ': ' . $value;
        }
        $headerLines[] = 'MIME-Version: 1.0';
        $headerLines[] = 'Content-Type: multipart/related; boundary="' . $relatedBoundary . '"';
        $lines = [];
        $lines[] = '--' . $relatedBoundary;
        $lines[] = 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"';
        $lines[] = '';
        $lines[] = '--' . $altBoundary;
        $lines[] = 'Content-Type: text/plain; charset=UTF-8';
        $lines[] = 'Content-Transfer-Encoding: quoted-printable';
        $lines[] = '';
        $lines[] = quoted_printable_encode($text);
        $lines[] = '--' . $altBoundary;
        $lines[] = 'Content-Type: text/html; charset=UTF-8';
        $lines[] = 'Content-Transfer-Encoding: quoted-printable';
        $lines[] = '';
        $lines[] = quoted_printable_encode($html);
        $lines[] = '--' . $altBoundary . '--';
        foreach ($attachments as $attachment) {
            if (empty($attachment['path']) || !is_file((string)$attachment['path'])) {
                continue;
            }
            $lines[] = '--' . $relatedBoundary;
            $lines[] = 'Content-Type: ' . ($attachment['content_type'] ?? 'application/octet-stream') . '; name="' . ($attachment['filename'] ?? basename((string)$attachment['path'])) . '"';
            $lines[] = 'Content-Transfer-Encoding: base64';
            $lines[] = 'Content-ID: <' . ($attachment['content_id'] ?? self::LOGO_CID) . '>';
            $lines[] = 'Content-Disposition: ' . ($attachment['disposition'] ?? 'inline') . '; filename="' . ($attachment['filename'] ?? basename((string)$attachment['path'])) . '"';
            $lines[] = '';
            $lines[] = chunk_split(base64_encode((string)file_get_contents((string)$attachment['path'])));
        }
        $lines[] = '--' . $relatedBoundary . '--';
        return [
            'to' => (string)($subscription['email'] ?? ''),
            'subject' => $subject,
            'headers' => $headerLines,
            'body' => implode("\r\n", $lines),
        ];
    }

    public function manageUrl(array $subscription): string
    {
        return Config::publicBaseUrl() . 'alert.php?token=' . rawurlencode((string)$subscription['token']);
    }

    public function unsubscribeUrl(array $subscription): string
    {
        return Config::publicBaseUrl() . 'alert.php?action=unsubscribe&token=' . rawurlencode((string)$subscription['token']);
    }

    public function unenrolUrl(array $subscription): string
    {
        return $this->unsubscribeUrl($subscription);
    }

    public function preferenceSummary(array $subscription): string
    {
        $parts = [];
        $parts[] = 'frequency: ' . ($subscription['frequency'] ?? 'daily');
        $parts[] = 'substances: ' . ($subscription['substances'] ?? 'psilocybin,psilocin');
        foreach (['keywords', 'author', 'journal', 'topic', 'cited_doi'] as $key) {
            if (!empty($subscription[$key])) {
                $parts[] = $key . ': ' . $subscription[$key];
            }
        }
        return implode('; ', $parts);
    }

    public function subjectLine(array $subscription, int $count): string
    {
        $scope = !empty($subscription['cited_doi']) ? 'publications citing ' . $subscription['cited_doi'] : ($this->isBroadAlert($subscription) ? 'psilocybin/psilocin publications' : 'matched research publications');
        return $count . ' new ' . $scope . ' (' . gmdate('Y-m-d') . ')';
    }

    public function isBroadAlert(array $subscription): bool
    {
        return empty($subscription['keywords']) && empty($subscription['author']) && empty($subscription['journal']) && empty($subscription['topic']) && empty($subscription['cited_doi']);
    }

    private function formatAddress(string $email, string $name): string
    {
        $name = trim(str_replace(['"', "\r", "\n"], '', $name));
        return '"' . addcslashes($name, '"\\') . '" <' . $email . '>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    private function alreadyDelivered(int $subscriptionId, int $publicationId, string $frequency): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM alert_deliveries WHERE subscription_id = :s AND publication_id = :p AND frequency = :f');
        $stmt->execute(['s' => $subscriptionId, 'p' => $publicationId, 'f' => $frequency]);
        return (bool)$stmt->fetchColumn();
    }

    private function markDelivered(int $subscriptionId, int $publicationId, string $frequency): void
    {
        $stmt = $this->db->pdo()->prepare('INSERT OR IGNORE INTO alert_deliveries (subscription_id, publication_id, frequency, generated_at) VALUES (:s, :p, :f, :g)');
        $stmt->execute(['s' => $subscriptionId, 'p' => $publicationId, 'f' => $frequency, 'g' => current_utc()]);
    }

    private function hydrateSubscription(array $row): array
    {
        if (!empty($row['email_cipher'])) {
            $row['email'] = SensitiveData::decrypt((string)$row['email_cipher']);
        }
        if (!empty($row['token_cipher'])) {
            $row['token'] = SensitiveData::decrypt((string)$row['token_cipher']);
        }
        if (!empty($row['confirmation_token_cipher'])) {
            $row['confirmation_token'] = SensitiveData::decrypt((string)$row['confirmation_token_cipher']);
        }
        return $row;
    }
}
