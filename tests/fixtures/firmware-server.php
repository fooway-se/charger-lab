<?php

declare(strict_types=1);

$port = (int) ($argv[1] ?? 0);
$certificate = (string) ($argv[2] ?? '');
$packagePath = (string) ($argv[3] ?? '');
$verificationFailurePath = (string) ($argv[4] ?? '');
$context = stream_context_create(['ssl' => ['local_cert' => $certificate]]);
$server = stream_socket_server(
    "tls://127.0.0.1:{$port}",
    $errorNumber,
    $errorMessage,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context,
);
if (!is_resource($server)) {
    fwrite(STDERR, "Could not start firmware test server: {$errorMessage}\n");
    exit(1);
}

$package = file_get_contents($packagePath);
if (!is_string($package)) {
    fwrite(STDERR, "Could not read the firmware test package.\n");
    exit(1);
}
$verificationFailurePackage = $verificationFailurePath === '' ? false : file_get_contents($verificationFailurePath);
if ($verificationFailurePath !== '' && !is_string($verificationFailurePackage)) {
    fwrite(STDERR, "Could not read the firmware verification-failure package.\n");
    exit(1);
}

fwrite(STDOUT, "Firmware test server listening on {$port}\n");
while ($client = @stream_socket_accept($server, -1)) {
    stream_set_timeout($client, 5);
    $requestLine = fgets($client);
    while (($line = fgets($client)) !== false && $line !== "\r\n" && $line !== "\n") {
    }

    if (!is_string($requestLine)) {
        fclose($client);
        continue;
    }
    if (str_starts_with($requestLine, 'GET /firmware.bin ')) {
        $responsePackage = $package;
    } elseif (str_starts_with($requestLine, 'GET /firmware-install-verification-failed.bin ')
        && is_string($verificationFailurePackage)) {
        $responsePackage = $verificationFailurePackage;
    } else {
        fwrite($client, "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        fclose($client);
        continue;
    }

    fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: application/octet-stream\r\nContent-Length: " . strlen($responsePackage) . "\r\nConnection: close\r\n\r\n");
    $offset = 0;
    while ($offset < strlen($responsePackage)) {
        $written = fwrite($client, substr($responsePackage, $offset));
        if (!is_int($written) || $written < 1) {
            break;
        }
        $offset += $written;
    }
    fclose($client);
}
