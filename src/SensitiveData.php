<?php
declare(strict_types=1);

final class SensitiveData
{
    private const PREFIX_SODIUM = 'sodium:v1:';
    private const PREFIX_OPENSSL = 'aes256gcm:v1:';

    public static function encrypt(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $key = Config::sensitiveDataKey();
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($value, $nonce, $key);
            return self::PREFIX_SODIUM . Config::base64UrlEncode($nonce . $ciphertext);
        }

        $nonce = random_bytes(12);
        $ciphertext = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($ciphertext)) {
            throw new RuntimeException('Unable to encrypt sensitive data.');
        }
        return self::PREFIX_OPENSSL . Config::base64UrlEncode($nonce . $tag . $ciphertext);
    }

    public static function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $key = Config::sensitiveDataKey();
        if (str_starts_with($value, self::PREFIX_SODIUM)) {
            if (!function_exists('sodium_crypto_secretbox_open')) {
                throw new RuntimeException('Sodium is required to decrypt this sensitive data.');
            }
            $packed = Config::base64UrlDecode(substr($value, strlen(self::PREFIX_SODIUM)));
            $nonce = substr($packed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = substr($packed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
            if (!is_string($plain)) {
                throw new RuntimeException('Unable to decrypt sensitive data.');
            }
            return $plain;
        }
        if (str_starts_with($value, self::PREFIX_OPENSSL)) {
            $packed = Config::base64UrlDecode(substr($value, strlen(self::PREFIX_OPENSSL)));
            $nonce = substr($packed, 0, 12);
            $tag = substr($packed, 12, 16);
            $ciphertext = substr($packed, 28);
            $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
            if (!is_string($plain)) {
                throw new RuntimeException('Unable to decrypt sensitive data.');
            }
            return $plain;
        }
        return $value;
    }

    public static function blindIndex(string $purpose, string $value): string
    {
        return hash_hmac('sha256', self::canonical($purpose, $value), Config::sensitiveDataBlindIndexKey());
    }

    public static function canonicalEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    private static function canonical(string $purpose, string $value): string
    {
        $value = trim($value);
        if ($purpose === 'alert-email') {
            $value = self::canonicalEmail($value);
        }
        return $purpose . "\0" . $value;
    }
}
