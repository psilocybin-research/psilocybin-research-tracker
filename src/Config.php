<?php
declare(strict_types=1);

final class Config
{
    public static function baseDir(): string
    {
        return dirname(__DIR__);
    }

    public static function dataDir(): string
    {
        $configured = getenv('PUBLICATION_TRACKER_DATA_DIR');
        if (is_string($configured) && $configured !== '') {
            $dir = $configured;
        } else {
            $dir = self::baseDir() . '/data';
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    public static function databaseDsn(): string
    {
        return getenv('PUBLICATION_TRACKER_DSN') ?: 'sqlite:' . self::dataDir() . '/publications.sqlite';
    }

    public static function databaseUser(): ?string
    {
        $user = getenv('PUBLICATION_TRACKER_DB_USER');
        return $user === false ? null : $user;
    }

    public static function databasePassword(): ?string
    {
        $pass = getenv('PUBLICATION_TRACKER_DB_PASSWORD');
        return $pass === false ? null : $pass;
    }

    public static function adminToken(): string
    {
        $token = getenv('PUBLICATION_TRACKER_ADMIN_TOKEN');
        if (is_string($token) && strlen($token) >= 24) {
            return $token;
        }

        $file = self::dataDir() . '/admin_token.php';
        if (is_file($file)) {
            $stored = include $file;
            if (is_string($stored) && strlen($stored) >= 24) {
                return $stored;
            }
        }

        $generated = bin2hex(random_bytes(24));
        file_put_contents($file, "<?php\nreturn '" . addslashes($generated) . "';\n", LOCK_EX);
        chmod($file, 0640);
        return $generated;
    }

    public static function sensitiveDataKey(): string
    {
        $key = self::configuredSensitiveDataKey('PUBLICATION_TRACKER_ENCRYPTION_KEY');
        if ($key !== null) {
            return $key;
        }

        $file = self::dataDir() . '/encryption_key.php';
        if (is_file($file)) {
            $stored = include $file;
            if (is_array($stored) && isset($stored['encryption'], $stored['blind_index'])) {
                $decoded = self::base64UrlDecode((string)$stored['encryption']);
                if (strlen($decoded) === 32) {
                    return $decoded;
                }
            }
        }

        $generated = [
            'encryption' => self::base64UrlEncode(random_bytes(32)),
            'blind_index' => self::base64UrlEncode(random_bytes(32)),
        ];
        file_put_contents($file, "<?php\nreturn " . var_export($generated, true) . ";\n", LOCK_EX);
        chmod($file, 0640);
        return self::base64UrlDecode($generated['encryption']);
    }

    public static function sensitiveDataBlindIndexKey(): string
    {
        $key = self::configuredSensitiveDataKey('PUBLICATION_TRACKER_BLIND_INDEX_KEY');
        if ($key !== null) {
            return $key;
        }

        $file = self::dataDir() . '/encryption_key.php';
        if (!is_file($file)) {
            self::sensitiveDataKey();
        }
        $stored = is_file($file) ? include $file : null;
        if (is_array($stored) && isset($stored['blind_index'])) {
            $decoded = self::base64UrlDecode((string)$stored['blind_index']);
            if (strlen($decoded) === 32) {
                return $decoded;
            }
        }
        throw new RuntimeException('Sensitive data blind-index key is not configured.');
    }

    private static function configuredSensitiveDataKey(string $name): ?string
    {
        $value = getenv($name);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        $decoded = self::base64UrlDecode($value);
        if (strlen($decoded) === 32) {
            return $decoded;
        }
        if (strlen($value) === 32) {
            return $value;
        }
        throw new RuntimeException($name . ' must be a 32-byte raw value or base64url-encoded 32-byte value.');
    }

    public static function ncbiEmail(): ?string
    {
        $email = getenv('PUBLICATION_TRACKER_NCBI_EMAIL');
        return $email === false ? null : $email;
    }

    public static function ncbiApiKey(): ?string
    {
        $key = getenv('PUBLICATION_TRACKER_NCBI_API_KEY');
        return $key === false ? null : $key;
    }

    public static function openAlexApiKey(): ?string
    {
        $key = getenv('PUBLICATION_TRACKER_OPENALEX_API_KEY');
        if (is_string($key) && trim($key) !== '') {
            return trim($key);
        }
        $file = self::dataDir() . '/openalex_api.php';
        if (is_file($file)) {
            $stored = include $file;
            if (is_array($stored) && isset($stored['api_key']) && is_string($stored['api_key']) && trim($stored['api_key']) !== '') {
                return trim($stored['api_key']);
            }
        }
        return null;
    }

    public static function publicBaseUrl(): string
    {
        $url = getenv('PUBLICATION_TRACKER_PUBLIC_URL') ?: 'https://psilocybin-research.com/';
        return rtrim($url, '/') . '/';
    }

    public static function alertFromEmail(): string
    {
        $email = getenv('PUBLICATION_TRACKER_ALERT_FROM');
        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : 'noreply@psilocybin-research.com';
    }

    public static function alertFromName(): string
    {
        $name = getenv('PUBLICATION_TRACKER_ALERT_FROM_NAME');
        return is_string($name) && trim($name) !== '' ? trim($name) : 'Psilocybin Research Publication Tracker';
    }

    public static function vapidSubject(): string
    {
        $subject = getenv('PUBLICATION_TRACKER_VAPID_SUBJECT');
        if (is_string($subject) && trim($subject) !== '') {
            return trim($subject);
        }
        return 'mailto:' . self::alertFromEmail();
    }

    public static function vapidKeys(): array
    {
        $public = getenv('PUBLICATION_TRACKER_VAPID_PUBLIC_KEY');
        $private = getenv('PUBLICATION_TRACKER_VAPID_PRIVATE_KEY');
        if (is_string($public) && $public !== '' && is_string($private) && $private !== '') {
            return ['public' => $public, 'private' => str_replace('\\n', "\n", $private)];
        }

        $file = self::dataDir() . '/push_vapid.php';
        if (is_file($file)) {
            $stored = include $file;
            if (is_array($stored) && !empty($stored['public']) && !empty($stored['private'])) {
                return $stored;
            }
        }

        $config = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
        $key = openssl_pkey_new($config);
        if (!$key) {
            throw new RuntimeException('Unable to generate VAPID key pair.');
        }
        openssl_pkey_export($key, $privatePem);
        $details = openssl_pkey_get_details($key);
        $x = $details['ec']['x'] ?? null;
        $y = $details['ec']['y'] ?? null;
        if (!is_string($x) || !is_string($y)) {
            throw new RuntimeException('Unable to export VAPID public key.');
        }
        $generated = [
            'public' => self::base64UrlEncode("\x04" . $x . $y),
            'private' => $privatePem,
        ];
        file_put_contents($file, "<?php\nreturn " . var_export($generated, true) . ";\n", LOCK_EX);
        chmod($file, 0640);
        return $generated;
    }

    public static function vapidPublicKey(): string
    {
        return (string)self::vapidKeys()['public'];
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4)) ?: '';
    }

    public static function logFile(): string
    {
        $file = getenv('PUBLICATION_TRACKER_LOG_FILE');
        return is_string($file) && trim($file) !== '' ? $file : self::dataDir() . '/app.log';
    }

    public static function heartbeatDir(): string
    {
        $dir = getenv('PUBLICATION_TRACKER_HEARTBEAT_DIR') ?: self::dataDir() . '/heartbeat';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    public static function backupDir(): string
    {
        $dir = getenv('PUBLICATION_TRACKER_BACKUP_DIR') ?: self::dataDir() . '/backups';
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }
}
