<?php

declare(strict_types=1);

/**
 * Serves a local OCPP 2.0.1 WebSocket endpoint for emulator development.
 */
$port = (int) (getenv('FAKE_CSMS_PORT') ?: 9000);
$server = stream_socket_server("tcp://0.0.0.0:{$port}", $errorNumber, $errorMessage);
if (!is_resource($server)) {
    fwrite(STDERR, "Could not start fake CSMS: {$errorMessage}\n");
    exit(1);
}

fwrite(STDOUT, "Fake CSMS listening on 0.0.0.0:{$port} (ocpp2.0.1)\n");

while ($client = @stream_socket_accept($server, -1)) {
    if (function_exists('pcntl_fork')) {
        $processId = pcntl_fork();
        if ($processId > 0) {
            fclose($client);
            while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {
            }

            continue;
        }

        if ($processId === 0) {
            fclose($server);
        }
    }

    stream_set_timeout($client, 86_400);
    try {
        serveClient($client);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Fake CSMS client error: ' . $exception->getMessage() . "\n");
    } finally {
        fclose($client);
    }

    if (function_exists('pcntl_fork') && $processId === 0) {
        exit(0);
    }
}

function serveClient($client): void
{
    $requestLine = fgets($client);
    if (!is_string($requestLine)) {
        return;
    }

    $headers = [];
    while (($line = fgets($client)) !== false && $line !== "\r\n" && $line !== "\n") {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }

    $key = $headers['sec-websocket-key'] ?? '';
    $protocols = array_map('trim', explode(',', $headers['sec-websocket-protocol'] ?? ''));
    if ($key === '' || !in_array('ocpp2.0.1', $protocols, true)) {
        fwrite($client, "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n");
        return;
    }

    $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    $response = "HTTP/1.1 101 Switching Protocols\r\n"
        . "Upgrade: websocket\r\nConnection: Upgrade\r\n"
        . "Sec-WebSocket-Accept: {$accept}\r\n"
        . "Sec-WebSocket-Protocol: ocpp2.0.1\r\n\r\n";
    writeAll($client, $response);
    fwrite(STDOUT, 'Connected ' . trim($requestLine) . "\n");

    $bootNotifications = 0;
    $pendingBootCount = (int) (getenv('FAKE_CSMS_PENDING_BOOT_COUNT') ?: 0);
    $serverCallSent = false;
    while (true) {
        $frame = readFrame($client);
        if ($frame === null) {
            fwrite(STDOUT, 'Disconnected ' . trim($requestLine) . "\n");
            return;
        }

        [$opcode, $payload] = $frame;
        if ($opcode === 0x8) {
            writeFrame($client, 0x8, '');
            return;
        }

        if ($opcode === 0x9) {
            writeFrame($client, 0xA, $payload);
            continue;
        }

        if ($opcode !== 0x1) {
            continue;
        }

        fwrite(STDOUT, "OCPP in: {$payload}\n");
        $call = json_decode($payload, true);
        if (!is_array($call) || ($call[0] ?? null) !== 2 || !is_string($call[1] ?? null)) {
            continue;
        }

        $action = $call[2] ?? '';
        if (!in_array($action, ['BootNotification', 'Authorize', 'Heartbeat', 'StatusNotification', 'TransactionEvent'], true)) {
            $replyFrame = [4, $call[1], 'NotImplemented', 'Unsupported action: ' . (string) $action, new stdClass()];
        } else {
            $result = match ($action) {
                'BootNotification' => ['status' => ++$bootNotifications <= $pendingBootCount ? 'Pending' : 'Accepted', 'currentTime' => gmdate('Y-m-d\TH:i:s\Z'), 'interval' => (int) (getenv('FAKE_CSMS_HEARTBEAT_INTERVAL') ?: 300)],
                'Authorize' => ['idTokenInfo' => ['status' => 'Accepted']],
                'Heartbeat' => ['currentTime' => gmdate('Y-m-d\TH:i:s\Z')],
                'StatusNotification' => new stdClass(),
                'TransactionEvent' => isset($call[3]['idToken'])
                    ? ['idTokenInfo' => ['status' => 'Accepted']]
                    : new stdClass(),
            };
            $replyFrame = [3, $call[1], $result];
        }

        $reply = json_encode($replyFrame, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        fwrite(STDOUT, "OCPP out: {$reply}\n");
        if (getenv('FAKE_CSMS_FRAGMENT_RESPONSE') === '1') {
            $splitAt = max(1, intdiv(strlen($reply), 2));
            writeFrame($client, 0x1, substr($reply, 0, $splitAt), false);
            writeFrame($client, 0x0, substr($reply, $splitAt));
        } else {
            writeFrame($client, 0x1, $reply);
        }

        if ($action === 'BootNotification'
            && ($result['status'] ?? null) === 'Accepted'
            && !$serverCallSent
            && getenv('FAKE_CSMS_SEND_CALL') === '1') {
            $serverCallSent = true;
            $serverCallId = 'fake-csms-call-' . $call[1];
            $serverCall = json_encode([2, $serverCallId, 'GetVariables', ['getVariableData' => []]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            writeFrame($client, 0x1, $serverCall);
            while (($stationResponse = readFrame($client)) !== null) {
                if ($stationResponse[0] !== 0x1) {
                    continue;
                }

                $stationMessage = json_decode($stationResponse[1], true);
                if (is_array($stationMessage) && ($stationMessage[0] ?? null) === 4 && ($stationMessage[1] ?? null) === $serverCallId) {
                    fwrite(STDOUT, 'Station response: ' . $stationResponse[1] . "\n");
                    break;
                }
                if (is_array($stationMessage) && ($stationMessage[0] ?? null) === 2 && ($stationMessage[2] ?? null) === 'StatusNotification') {
                    $statusReply = json_encode([3, $stationMessage[1], new stdClass()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                    writeFrame($client, 0x1, $statusReply);
                }
            }
        }
    }
}

function readFrame($stream): ?array
{
    $header = readExact($stream, 2);
    if ($header === null) {
        return null;
    }

    $first = ord($header[0]);
    $second = ord($header[1]);
    $opcode = $first & 0x0f;
    $length = $second & 0x7f;
    if ($length === 126) {
        $extended = readExact($stream, 2);
        if ($extended === null) {
            return null;
        }

        $length = unpack('n', $extended)[1];
    } elseif ($length === 127) {
        $extended = readExact($stream, 8);
        if ($extended === null) {
            return null;
        }

        $size = unpack('Nhigh/Nlow', $extended);
        if ($size['high'] !== 0) {
            return null;
        }

        $length = $size['low'];
    }

    if ($length > 1_048_576) {
        return null;
    }

    $mask = ($second & 0x80) !== 0 ? readExact($stream, 4) : null;
    $payload = readExact($stream, $length);
    if ($payload === null || (($second & 0x80) !== 0 && $mask === null)) {
        return null;
    }

    if (is_string($mask)) {
        for ($index = 0; $index < $length; $index++) {
            $payload[$index] = $payload[$index] ^ $mask[$index % 4];
        }
    }

    return [$opcode, $payload];
}

function readExact($stream, int $length): ?string
{
    $data = '';
    while (strlen($data) < $length) {
        $chunk = fread($stream, $length - strlen($data));
        if (!is_string($chunk) || $chunk === '') {
            return null;
        }

        $data .= $chunk;
    }

    return $data;
}

function writeFrame($stream, int $opcode, string $payload, bool $final = true): void
{
    $length = strlen($payload);
    $frame = chr(($final ? 0x80 : 0) | $opcode);
    if ($length < 126) {
        $frame .= chr($length);
    } elseif ($length <= 0xffff) {
        $frame .= chr(126) . pack('n', $length);
    } else {
        $frame .= chr(127) . pack('N2', 0, $length);
    }

    writeAll($stream, $frame . $payload);
}

function writeAll($stream, string $data): void
{
    $offset = 0;
    while ($offset < strlen($data)) {
        $written = fwrite($stream, substr($data, $offset));
        if ($written === false || $written === 0) {
            return;
        }

        $offset += $written;
    }
}
