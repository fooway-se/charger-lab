<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

/** Uploads station log documents to the HTTP(S) location supplied by the CSMS. */
final class LogFileUploader implements LogUploader
{
    public function upload(string $remoteLocation, string $filename, string $contents): bool
    {
        $url = $remoteLocation;
        if (str_ends_with((string) (parse_url($url, PHP_URL_PATH) ?: ''), '/')) {
            $url .= rawurlencode($filename);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/octet-stream',
                    'Content-Disposition: attachment; filename="' . addcslashes($filename, '"\\') . '"',
                    'Content-Length: ' . strlen($contents),
                    'Connection: close',
                ]),
                'content' => $contents,
                'timeout' => 3,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        set_error_handler(static fn (): bool => true);
        try {
            $response = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }

        if (!is_string($response)) {
            return false;
        }

        $responseHeaders = $http_response_header ?? [];
        foreach ($responseHeaders as $header) {
            if (preg_match('/\AHTTP\/\S+\s+(\d{3})\b/', $header, $matches) === 1) {
                return (int) $matches[1] >= 200 && (int) $matches[1] < 300;
            }
        }

        return false;
    }
}
