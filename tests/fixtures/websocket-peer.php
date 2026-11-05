<?php

declare(strict_types=1);

$mode = getenv('WEBSOCKET_TEST_MODE') ?: 'valid';
$isTls = str_starts_with($mode, 'tls-');
$certificatePem = '';
$serverContext = stream_context_create();
if ($isTls) {
    $privateKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $request = openssl_csr_new(['commonName' => 'localhost'], $privateKey);
    $certificate = openssl_csr_sign($request, null, $privateKey, 1);
    openssl_x509_export($certificate, $certificatePem);
    openssl_pkey_export($privateKey, $privateKeyPem);
    $certificateFile = tempnam(sys_get_temp_dir(), 'websocket-peer-');
    file_put_contents($certificateFile, $privateKeyPem . $certificatePem);
    stream_context_set_option($serverContext, 'ssl', 'local_cert', $certificateFile);
}

$server = stream_socket_server(($isTls ? 'tls' : 'tcp') . '://127.0.0.1:0', $errorNumber, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $serverContext);
if (!is_resource($server)) {
    fwrite(STDERR, $errorMessage . "\n");
    exit(1);
}

$address = stream_socket_get_name($server, false);
$port = (int) substr(strrchr($address, ':'), 1);
fwrite(STDOUT, 'READY:' . $port . ($isTls ? ':' . base64_encode($certificatePem) : '') . "\n");
$client = stream_socket_accept($server, 5);
if (!is_resource($client)) {
    exit(1);
}

$request = fgets($client);
if ($mode === 'require-origin-form' && $request !== "GET /?token=value HTTP/1.1\r\n") {
    fwrite($client, "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n");
    exit(0);
}

$headers = [];
while (($line = fgets($client)) !== false && $line !== "\r\n" && $line !== "\n") {
    if (str_contains($line, ':')) {
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
    }
}
if ($isTls && is_file($certificateFile)) {
    unlink($certificateFile);
}

$key = $headers['sec-websocket-key'] ?? '';
$accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
if ($mode === 'invalid-accept') {
    $accept = 'invalid-accept-key';
}
$subprotocol = $mode === 'incorrect-subprotocol' ? 'ocpp1.6' : 'ocpp2.0.1';
$statusLine = $mode === 'invalid-status' ? 'HTTP/1.1 200 OK' : 'HTTP/1.1 101 Switching Protocols';
$response = $statusLine . "\r\n";
if ($mode !== 'missing-upgrade') {
    $response .= "Upgrade: websocket\r\n";
}
if ($mode !== 'missing-connection') {
    $response .= "Connection: Upgrade\r\n";
}
$response .= "Sec-WebSocket-Accept: {$accept}\r\nSec-WebSocket-Protocol: {$subprotocol}\r\n";
if ($mode === 'unexpected-extension') {
    $response .= "Sec-WebSocket-Extensions: permessage-deflate\r\n";
}
if ($mode === 'duplicate-accept') {
    $response .= "Sec-WebSocket-Accept: {$accept}\r\n";
}
if ($mode === 'oversized-header') {
    $response .= 'X-Test: ' . str_repeat('a', 8_200) . "\r\n";
}
if ($mode === 'oversized-headers') {
    for ($index = 0; $index < 9; $index++) {
        $response .= 'X-Test-' . $index . ': ' . str_repeat('a', 1_900) . "\r\n";
    }
}
if ($mode === 'malformed-header') {
    $response .= "Malformed Header\r\n";
}

if ($mode === 'no-response') {
    sleep(3);
    exit(0);
}

if ($mode === 'incomplete-handshake') {
    fwrite($client, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Up");
    sleep(3);
    exit(0);
}

if ($mode === 'closed-handshake') {
    fclose($client);
    exit(0);
}

if ($mode === 'slow-handshake') {
    fwrite($client, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\n");
    usleep(600_000);
    fwrite($client, "Connection: Upgrade\r\n");
    usleep(600_000);
    fwrite($client, "Sec-WebSocket-Accept: {$accept}\r\nSec-WebSocket-Protocol: ocpp2.0.1\r\n\r\n");
} else {
    fwrite($client, $response . "\r\n");
}

match ($mode) {
    'tls-valid', 'tls-invalid-certificate' => fwrite($client, "\x81\x02{}"),
    'fragmented-text' => fwrite($client, "\x01\x01{\x80\x01}"),
    'ping-pong' => respondToPing($client),
    'close-after-handshake' => fwrite($client, "\x88\x02\x03\xE8"),
    'reserved-bits' => fwrite($client, "\xC1\x02{}"),
    'unknown-opcode' => fwrite($client, "\x83\x00"),
    'masked-server-frame' => fwrite($client, "\x81\x82"),
    'unexpected-continuation' => fwrite($client, "\x80\x00"),
    'interleaved-data-frame' => fwrite($client, "\x01\x01x\x81\x01{"),
    'fragmented-ping' => fwrite($client, "\x09\x00"),
    'oversized-ping' => fwrite($client, "\x89\x7E\x00\x7E"),
    'fragmented-pong' => fwrite($client, "\x0A\x00"),
    'short-close' => fwrite($client, "\x88\x01\x00"),
    'invalid-close-code' => fwrite($client, "\x88\x02\x03\xED"),
    'invalid-close-reason' => fwrite($client, "\x88\x03\x03\xE8\xFF"),
    'invalid-utf8' => fwrite($client, "\x81\x01\xFF"),
    'nonminimal-frame' => fwrite($client, "\x81\x7E\x00\x02{}"),
    'slow-frame' => sendSlowFrame($client),
    'oversized-frame' => fwrite($client, "\x81\x7F" . pack('N2', 0, 1_048_577)),
    'oversized-fragmented-message' => sendOversizedFragmentedMessage($client),
    'disconnect' => usleep(300_000),
    'ping-flood' => sendPingFlood($client),
    default => fwrite($client, "\x81\x02{}"),
};

usleep(300_000);
fclose($client);
fclose($server);
if ($isTls && is_file($certificateFile)) {
    unlink($certificateFile);
}

function sendSlowFrame($client): void
{
    fwrite($client, "\x81\x02");
    usleep(600_000);
    fwrite($client, '{');
    usleep(600_000);
    fwrite($client, '}');
}

function sendPingFlood($client): never
{
    while (true) {
        fwrite($client, "\x89\x00");
        usleep(10_000);
    }
}

function sendOversizedFragmentedMessage($client): void
{
    fwrite($client, "\x01\x7F" . pack('N2', 0, 1_048_576) . str_repeat('x', 1_048_576) . "\x80\x01");
}

function respondToPing($client): void
{
    fwrite($client, "\x89\x05token");
    $frame = readClientFrame($client);
    if ($frame === [0xA, 'token']) {
        fwrite($client, "\x81\x02{}");
    }
}

function readClientFrame($stream): ?array
{
    $header = readClientBytes($stream, 2);
    if ($header === null) {
        return null;
    }

    $length = ord($header[1]) & 0x7f;
    if ($length === 126) {
        $extended = readClientBytes($stream, 2);
        if ($extended === null) {
            return null;
        }
        $length = unpack('n', $extended)[1];
    }

    $mask = readClientBytes($stream, 4);
    $payload = readClientBytes($stream, $length);
    if ($mask === null || $payload === null) {
        return null;
    }

    for ($index = 0; $index < $length; $index++) {
        $payload[$index] = $payload[$index] ^ $mask[$index % 4];
    }

    return [ord($header[0]) & 0x0f, $payload];
}

function readClientBytes($stream, int $length): ?string
{
    $payload = '';
    while (strlen($payload) < $length) {
        $chunk = fread($stream, $length - strlen($payload));
        if (!is_string($chunk) || $chunk === '') {
            return null;
        }

        $payload .= $chunk;
    }

    return $payload;
}
