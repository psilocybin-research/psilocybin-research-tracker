<?php
declare(strict_types=1);

/** Shared release identity for PHP entry points and demand-loaded features. */
final class FrontendAssets
{
    public const VERSION = '20261122-contextual-install-v1';

    public static function url(string $path): string
    {
        if (!preg_match('~^assets/[a-zA-Z0-9_./-]+\.(?:css|js|mjs)$~D', $path) || str_contains($path, '..')) {
            throw new InvalidArgumentException('Unknown frontend asset path.');
        }
        require_once __DIR__ . '/AppRelease.php';
        return '/' . $path . '?v=' . self::VERSION . '&r=' . AppRelease::id();
    }
}
