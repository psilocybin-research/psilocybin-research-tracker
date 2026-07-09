<?php
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $paths = [
        __DIR__ . '/' . str_replace('\\', '/', $class) . '.php',
        __DIR__ . '/Fetchers/' . str_replace('\\', '/', $class) . '.php',
    ];
    foreach ($paths as $path) {
        if (is_file($path)) {
            require_once $path;
            return;
        }
    }
});

if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $string, ?string $encoding = null): string
    {
        return strtolower($string);
    }
}

if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($string, $start) : substr($string, $start, $length);
    }
}

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int
    {
        return strlen($string);
    }
}

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalize_title(string $title): string
{
    $title = clean_scientific_text($title);
    $title = mb_strtolower($title, 'UTF-8');
    $title = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title) ?? $title;
    return trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
}

function clean_scientific_text(?string $text): string
{
    $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = strip_tags($text);
    $text = str_replace(["\xC2\xA0", '‐', '‑', '‒', '–', '—'], [' ', '-', '-', '-', '-', '-'], $text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\b5\s*-\s*HT\s*([0-9]+[A-Z]?)\b/iu', '5-HT$1', $text) ?? $text;
    $text = preg_replace('/\b([A-Z]{1,4})\s+([0-9]+[A-Z]?)\b/u', '$1$2', $text) ?? $text;
    $text = preg_replace('/\s+([,.;:!?])/u', '$1', $text) ?? $text;
    return trim($text);
}

function clean_paper(array $paper): array
{
    foreach (['title', 'abstract', 'journal', 'authors', 'keywords'] as $field) {
        if (array_key_exists($field, $paper) && $paper[$field] !== null) {
            $paper[$field] = clean_scientific_text((string)$paper[$field]);
        }
    }
    return $paper;
}

function normalize_doi(?string $doi): ?string
{
    if ($doi === null) {
        return null;
    }
    $doi = trim(mb_strtolower($doi, 'UTF-8'));
    $doi = preg_replace('~^https?://(dx\.)?doi\.org/~i', '', $doi) ?? $doi;
    $doi = preg_replace('~^doi:\s*~i', '', $doi) ?? $doi;
    return $doi === '' ? null : $doi;
}

function parse_date_or_null(?string $date): ?string
{
    if ($date === null || trim($date) === '') {
        return null;
    }
    $date = trim($date);
    if (preg_match('/^\d{4}$/', $date)) {
        return $date . '-01-01';
    }
    if (preg_match('/^\d{4}-\d{2}$/', $date)) {
        return $date . '-01';
    }
    $ts = strtotime($date);
    return $ts === false ? null : gmdate('Y-m-d', $ts);
}

function current_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function format_utc_display(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return 'No successful update yet';
    }
    $timezone = new DateTimeZone('UTC');
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $timezone);
    if (!$date) {
        $date = new DateTimeImmutable($value, $timezone);
    }
    return $date->setTimezone($timezone)->format('M j, Y H:i') . ' UTC';
}

function request_value(string $key, ?string $default = null): ?string
{
    $value = $_GET[$key] ?? $_POST[$key] ?? $default;
    if (is_array($value)) {
        return $default;
    }
    $value = trim((string)$value);
    return $value === '' ? $default : $value;
}

function request_array(string $key): array
{
    $value = $_GET[$key] ?? $_POST[$key] ?? [];
    if (!is_array($value)) {
        $value = [$value];
    }
    $items = array_map(function ($item): string {
        return trim((string)$item);
    }, $value);
    return array_values(array_filter($items, function (string $item): bool {
        return $item !== '';
    }));
}

function download_request_parts(): array
{
    $downloadAt = preg_replace('/[^0-9T-]/', '', (string)request_value('download_at', ''));
    if (!preg_match('/^\d{8}T\d{6}$/', $downloadAt)) {
        $downloadAt = gmdate('Ymd\THis');
    }

    $downloadId = strtolower(preg_replace('/[^a-zA-Z0-9-]/', '', (string)request_value('download_id', '')));
    if ($downloadId === '') {
        $downloadId = bin2hex(random_bytes(4));
    }

    return [$downloadAt, substr($downloadId, 0, 24)];
}

function download_filename(string $prefix, string $extension): string
{
    [$downloadAt, $downloadId] = download_request_parts();
    $safePrefix = trim(preg_replace('/[^a-zA-Z0-9-]+/', '-', $prefix) ?? '', '-');
    $safeExtension = trim(preg_replace('/[^a-zA-Z0-9]+/', '', $extension) ?? '');
    return ($safePrefix !== '' ? $safePrefix : 'download') . '-' . $downloadAt . '-' . $downloadId . '.' . ($safeExtension !== '' ? $safeExtension : 'dat');
}

require_once __DIR__ . '/ViewHelpers.php';
