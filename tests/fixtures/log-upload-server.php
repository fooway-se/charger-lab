<?php

declare(strict_types=1);

$requestsPath = getenv('LOG_UPLOAD_REQUESTS_PATH') ?: '';
$body = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];
$record = [
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'path' => $_SERVER['REQUEST_URI'] ?? '',
    'headers' => $headers,
    'body' => is_string($body) ? $body : '',
];
if ($requestsPath !== '') {
    file_put_contents($requestsPath, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

$status = str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/fail/') ? 503 : 201;
http_response_code($status);
header('Content-Type: text/plain');
echo $status === 201 ? 'stored' : 'unavailable';
