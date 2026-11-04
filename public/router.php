<?php

declare(strict_types=1);

/**
 * Serves standalone frontend assets and forwards API paths to the HTTP entry point.
 */
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) && $path !== '' ? $path : '/';

if ($path === '/') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    return true;
}

$file = realpath(__DIR__ . $path);
$publicRoot = realpath(__DIR__);
if ($path !== '/index.php'
    && $file !== false
    && $publicRoot !== false
    && str_starts_with($file, $publicRoot . DIRECTORY_SEPARATOR)
    && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
