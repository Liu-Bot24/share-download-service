<?php
declare(strict_types=1);

require_once __DIR__ . '/ShareStore.php';

function share_store(): ShareStore
{
    $root = dirname(__DIR__);
    return new ShareStore($root . '/files', $root . '/storage/stats.json');
}

function public_download_url(array $record): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'share.playai.ren';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
    return $scheme . '://' . $host . '/d/' . rawurlencode((string) $record['name']);
}

function format_bytes(int|float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $size = (float) $bytes;
    foreach ($units as $unit) {
        if ($size < 1024 || $unit === 'GB') {
            return $unit === 'B' ? sprintf('%d %s', (int) $size, $unit) : sprintf('%.1f %s', $size, $unit);
        }
        $size /= 1024;
    }
    return (string) $bytes . ' B';
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
