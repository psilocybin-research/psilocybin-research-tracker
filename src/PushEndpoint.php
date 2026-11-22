<?php
declare(strict_types=1);

/** Push subscriptions may target browser push providers, never arbitrary HTTPS servers. */
final class PushEndpoint
{
    public static function allowed(string $url): bool
    {
        if (strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f]/', $url)) return false;
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['port']) && $parts['port'] !== 443) return false;
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!in_array($host, ['fcm.googleapis.com', 'web.push.apple.com'], true)
            && !preg_match('/^(?:[a-z0-9-]+\.)*push\.services\.mozilla\.com$/D', $host)
            && !preg_match('/^(?:[a-z0-9-]+\.)+notify\.windows\.com$/D', $host)) return false;
        return isset($parts['path']) && $parts['path'] !== '/';
    }
    public static function validKeys(string $public, string $auth): bool
    {
        foreach ([$public, $auth] as $value) if (strlen($value) > 128 || !preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $value)) return false;
        $point = base64_decode(strtr($public, '-_', '+/'), true);
        $secret = base64_decode(strtr($auth, '-_', '+/'), true);
        if (!is_string($point) || strlen($point) !== 65 || $point[0] !== "\x04" || !is_string($secret) || strlen($secret) !== 16) return false;
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
        return @openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n") !== false;
    }

}
