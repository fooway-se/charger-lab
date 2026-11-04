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
    $requestParts = explode(' ', trim($requestLine));
    $stationKey = $requestParts[1] ?? '/';
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
    $bootCountPath = getenv('FAKE_CSMS_BOOT_COUNT_PATH') ?: '';
    $pendingBootCount = (int) (getenv('FAKE_CSMS_PENDING_BOOT_COUNT') ?: 0);
    $serverCallSent = false;
    $remoteStartSent = false;
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
        $bootStatus = $action === 'BootNotification'
            ? nextBootStatus($bootNotifications, $pendingBootCount, $bootCountPath, $stationKey)
            : null;
        if (!in_array($action, ['BootNotification', 'Authorize', 'Heartbeat', 'StatusNotification', 'TransactionEvent'], true)) {
            $replyFrame = [4, $call[1], 'NotImplemented', 'Unsupported action: ' . (string) $action, new stdClass()];
        } else {
            $result = match ($action) {
                'BootNotification' => [
                    'status' => $bootStatus,
                    'currentTime' => gmdate('Y-m-d\TH:i:s\Z'),
                    'interval' => (int) ($bootStatus === 'Pending'
                        ? (getenv('FAKE_CSMS_PENDING_BOOT_INTERVAL') ?: getenv('FAKE_CSMS_BOOT_INTERVAL') ?: 300)
                        : (getenv('FAKE_CSMS_HEARTBEAT_INTERVAL') ?: 300)),
                ],
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
            $serverCall = json_encode([2, $serverCallId, 'GetVariables', [
                'getVariableData' => [[
                    'component' => ['name' => 'AuthCtrlr'],
                    'variable' => ['name' => 'AuthorizeRemoteStart'],
                ]],
            ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            writeFrame($client, 0x1, $serverCall);
            while (($stationResponse = readFrame($client)) !== null) {
                if ($stationResponse[0] !== 0x1) {
                    continue;
                }

                $stationMessage = json_decode($stationResponse[1], true);
                if (is_array($stationMessage) && in_array($stationMessage[0] ?? null, [3, 4], true) && ($stationMessage[1] ?? null) === $serverCallId) {
                    fwrite(STDOUT, 'Station response: ' . $stationResponse[1] . "\n");
                    break;
                }
                if (is_array($stationMessage) && ($stationMessage[0] ?? null) === 2 && ($stationMessage[2] ?? null) === 'StatusNotification') {
                    $statusReply = json_encode([3, $stationMessage[1], new stdClass()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                    writeFrame($client, 0x1, $statusReply);
                }
            }

            $setVariableCallId = 'fake-csms-set-call-' . $call[1];
            $setVariableCall = json_encode([2, $setVariableCallId, 'SetVariables', [
                'setVariableData' => [[
                    'component' => ['name' => 'AuthCtrlr'],
                    'variable' => ['name' => 'AuthorizeRemoteStart'],
                    'attributeType' => 'Actual',
                    'attributeValue' => 'true',
                ]],
            ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            writeFrame($client, 0x1, $setVariableCall);
            while (($stationResponse = readFrame($client)) !== null) {
                if ($stationResponse[0] !== 0x1) {
                    continue;
                }

                $stationMessage = json_decode($stationResponse[1], true);
                if (is_array($stationMessage) && in_array($stationMessage[0] ?? null, [3, 4], true) && ($stationMessage[1] ?? null) === $setVariableCallId) {
                    fwrite(STDOUT, 'SetVariables station response: ' . $stationResponse[1] . "\n");
                    break;
                }
                if (is_array($stationMessage) && ($stationMessage[0] ?? null) === 2 && ($stationMessage[2] ?? null) === 'StatusNotification') {
                    $statusReply = json_encode([3, $stationMessage[1], new stdClass()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                    writeFrame($client, 0x1, $statusReply);
                }
            }

            if (getenv('FAKE_CSMS_SET_METER_SETTINGS') === '1') {
                $meterSettingsCallId = 'fake-csms-meter-settings-' . $call[1];
                $meterSettingsCall = json_encode([2, $meterSettingsCallId, 'SetVariables', [
                    'setVariableData' => [[
                        'component' => ['name' => 'SampledDataCtrlr'],
                        'variable' => ['name' => 'TxUpdatedInterval'],
                        'attributeValue' => '1',
                    ], [
                        'component' => ['name' => 'SampledDataCtrlr'],
                        'variable' => ['name' => 'TxUpdatedMeasurands'],
                        'attributeValue' => 'Energy.Active.Import.Register',
                    ], [
                        'component' => ['name' => 'SampledDataCtrlr'],
                        'variable' => ['name' => 'TxStartedMeasurands'],
                        'attributeValue' => '',
                    ], [
                        'component' => ['name' => 'SampledDataCtrlr'],
                        'variable' => ['name' => 'TxEndedMeasurands'],
                        'attributeValue' => 'Energy.Active.Import.Register',
                    ], [
                        'component' => ['name' => 'SampledDataCtrlr'],
                        'variable' => ['name' => 'TxEndedInterval'],
                        'attributeValue' => '1',
                    ]],
                ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                writeFrame($client, 0x1, $meterSettingsCall);
                while (($stationResponse = readFrame($client)) !== null) {
                    if ($stationResponse[0] !== 0x1) {
                        continue;
                    }

                    $stationMessage = json_decode($stationResponse[1], true);
                    if (!is_array($stationMessage)) {
                        continue;
                    }

                    if (in_array($stationMessage[0] ?? null, [3, 4], true)
                        && ($stationMessage[1] ?? null) === $meterSettingsCallId) {
                        fwrite(STDOUT, 'Meter settings station response: ' . $stationResponse[1] . "\n");
                        break;
                    }

                    if (($stationMessage[0] ?? null) === 2) {
                        $outgoingResult = match ($stationMessage[2] ?? '') {
                            'Heartbeat' => ['currentTime' => gmdate('Y-m-d\\TH:i:s\\Z')],
                            'TransactionEvent' => isset($stationMessage[3]['idToken'])
                                ? ['idTokenInfo' => ['status' => 'Accepted']]
                                : new stdClass(),
                            default => new stdClass(),
                        };
                        $outgoingResponse = json_encode([3, $stationMessage[1], $outgoingResult], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                        writeFrame($client, 0x1, $outgoingResponse);
                    }
                }
            }

            $remoteStartSentPath = getenv('FAKE_CSMS_REMOTE_START_SENT_PATH') ?: '';
            if (getenv('FAKE_CSMS_SEND_REMOTE_START') === '1'
                && !$remoteStartSent
                && ($remoteStartSentPath === '' || !is_file($remoteStartSentPath))) {
                $remoteStartSent = true;
                if ($remoteStartSentPath !== '') {
                    file_put_contents($remoteStartSentPath, 'sent', LOCK_EX);
                }

                $remoteStartCallId = 'fake-csms-remote-start-' . $call[1];
                $remoteStartCall = json_encode([2, $remoteStartCallId, 'RequestStartTransaction', [
                    'remoteStartId' => 42,
                    'idToken' => ['idToken' => 'REMOTE-42', 'type' => 'ISO14443'],
                    'evseId' => 1,
                ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                writeFrame($client, 0x1, $remoteStartCall);
                $requestAnswered = false;
                $transactionStarted = false;
                $remoteTransactionId = null;
                while ((!$requestAnswered || !$transactionStarted) && ($stationResponse = readFrame($client)) !== null) {
                    if ($stationResponse[0] !== 0x1) {
                        continue;
                    }

                    $stationMessage = json_decode($stationResponse[1], true);
                    if (!is_array($stationMessage)) {
                        continue;
                    }

                    if (($stationMessage[0] ?? null) === 3 && ($stationMessage[1] ?? null) === $remoteStartCallId) {
                        fwrite(STDOUT, 'RemoteStart station response: ' . $stationResponse[1] . "\n");
                        $requestAnswered = ($stationMessage[2]['status'] ?? null) === 'Accepted';
                    } elseif (($stationMessage[0] ?? null) === 2) {
                        $outgoingAction = $stationMessage[2] ?? '';
                        $outgoingPayload = $stationMessage[3] ?? [];
                        $outgoingResult = match ($outgoingAction) {
                            'Heartbeat' => ['currentTime' => gmdate('Y-m-d\\TH:i:s\\Z')],
                            'TransactionEvent' => isset($outgoingPayload['idToken'])
                                ? ['idTokenInfo' => ['status' => 'Accepted']]
                                : new stdClass(),
                            default => new stdClass(),
                        };
                        if ($outgoingAction === 'TransactionEvent'
                            && ($outgoingPayload['eventType'] ?? null) === 'Started'
                            && ($outgoingPayload['triggerReason'] ?? null) === 'RemoteStart') {
                            $transactionStarted = true;
                            $remoteTransactionId = $outgoingPayload['transactionInfo']['transactionId'] ?? null;
                            fwrite(STDOUT, 'RemoteStart TransactionEvent: ' . $stationResponse[1] . "\n");
                        }

                        $outgoingResponse = json_encode([3, $stationMessage[1], $outgoingResult], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                        writeFrame($client, 0x1, $outgoingResponse);
                    }
                }

                if ($requestAnswered && $transactionStarted && is_string($remoteTransactionId)) {
                    $transactionStatusCallId = 'fake-csms-transaction-status-' . $call[1];
                    $transactionStatusCall = json_encode([2, $transactionStatusCallId, 'GetTransactionStatus', [
                        'transactionId' => $remoteTransactionId,
                    ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                    writeFrame($client, 0x1, $transactionStatusCall);
                    while (($stationResponse = readFrame($client)) !== null) {
                        if ($stationResponse[0] !== 0x1) {
                            continue;
                        }

                        $stationMessage = json_decode($stationResponse[1], true);
                        if (!is_array($stationMessage)) {
                            continue;
                        }

                        if (($stationMessage[0] ?? null) === 3 && ($stationMessage[1] ?? null) === $transactionStatusCallId) {
                            fwrite(STDOUT, 'GetTransactionStatus station response: ' . $stationResponse[1] . "\n");
                            break;
                        }

                        if (($stationMessage[0] ?? null) === 2) {
                            $outgoingAction = $stationMessage[2] ?? '';
                            $outgoingResult = $outgoingAction === 'Heartbeat'
                                ? ['currentTime' => gmdate('Y-m-d\\TH:i:s\\Z')]
                                : new stdClass();
                            $outgoingResponse = json_encode([3, $stationMessage[1], $outgoingResult], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                            writeFrame($client, 0x1, $outgoingResponse);
                        }
                    }

                    $remoteStopCallId = 'fake-csms-remote-stop-' . $call[1];
                    $remoteStopCall = json_encode([2, $remoteStopCallId, 'RequestStopTransaction', [
                        'transactionId' => $remoteTransactionId,
                    ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                    writeFrame($client, 0x1, $remoteStopCall);
                    $stopAnswered = false;
                    $remoteStopReported = false;
                    while ((!$stopAnswered || !$remoteStopReported) && ($stationResponse = readFrame($client)) !== null) {
                        if ($stationResponse[0] !== 0x1) {
                            continue;
                        }

                        $stationMessage = json_decode($stationResponse[1], true);
                        if (!is_array($stationMessage)) {
                            continue;
                        }

                        if (($stationMessage[0] ?? null) === 3 && ($stationMessage[1] ?? null) === $remoteStopCallId) {
                            fwrite(STDOUT, 'RemoteStop station response: ' . $stationResponse[1] . "\n");
                            $stopAnswered = ($stationMessage[2]['status'] ?? null) === 'Accepted';
                        } elseif (($stationMessage[0] ?? null) === 2) {
                            $outgoingAction = $stationMessage[2] ?? '';
                            $outgoingPayload = $stationMessage[3] ?? [];
                            if ($outgoingAction === 'TransactionEvent'
                                && ($outgoingPayload['eventType'] ?? null) === 'Updated'
                                && ($outgoingPayload['triggerReason'] ?? null) === 'RemoteStop') {
                                $remoteStopReported = true;
                                fwrite(STDOUT, 'RemoteStop TransactionEvent: ' . $stationResponse[1] . "\n");
                            }

                            $outgoingResult = $outgoingAction === 'Heartbeat'
                                ? ['currentTime' => gmdate('Y-m-d\\TH:i:s\\Z')]
                                : new stdClass();
                            $outgoingResponse = json_encode([3, $stationMessage[1], $outgoingResult], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                            writeFrame($client, 0x1, $outgoingResponse);
                        }
                    }
                }
            }
        }

        if ($action === 'BootNotification'
            && ($result['status'] ?? null) === 'Rejected'
            && getenv('FAKE_CSMS_SEND_REJECTED_CALL') === '1') {
            $serverCallId = 'fake-csms-rejected-call-' . $call[1];
            $serverCall = json_encode([2, $serverCallId, 'GetVariables', [
                'getVariableData' => [[
                    'component' => ['name' => 'AuthCtrlr'],
                    'variable' => ['name' => 'AuthorizeRemoteStart'],
                ]],
            ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            writeFrame($client, 0x1, $serverCall);
            while (($stationResponse = readFrame($client)) !== null) {
                if ($stationResponse[0] !== 0x1) {
                    continue;
                }

                $stationMessage = json_decode($stationResponse[1], true);
                if (is_array($stationMessage) && ($stationMessage[0] ?? null) === 4 && ($stationMessage[1] ?? null) === $serverCallId) {
                    fwrite(STDOUT, 'Rejected station response: ' . $stationResponse[1] . "\n");
                    break;
                }
            }
        }
    }
}

function nextBootStatus(int &$bootNotifications, int $pendingBootCount, string $bootCountPath, string $stationKey): string
{
    $rejectedStation = getenv('FAKE_CSMS_REJECT_STATION') ?: '';
    if ($bootCountPath === '') {
        $bootNotifications++;
        $attempt = $bootNotifications;
    } else {
        $counter = fopen($bootCountPath, 'c+');
        if (!is_resource($counter)) {
            throw new RuntimeException('Could not lock the fake CSMS BootNotification counter.');
        }

        if (!flock($counter, LOCK_EX)) {
            fclose($counter);
            throw new RuntimeException('Could not lock the fake CSMS BootNotification counter.');
        }

        $storedCount = stream_get_contents($counter);
        $counts = json_decode(is_string($storedCount) && $storedCount !== '' ? $storedCount : '{}', true, flags: JSON_THROW_ON_ERROR);
        $attempt = (int) ($counts[$stationKey] ?? 0) + 1;
        $counts[$stationKey] = $attempt;
        ftruncate($counter, 0);
        rewind($counter);
        fwrite($counter, json_encode($counts, JSON_THROW_ON_ERROR));
        fflush($counter);
        flock($counter, LOCK_UN);
        fclose($counter);
    }

    if ($rejectedStation !== ''
        && str_ends_with(rawurldecode($stationKey), $rejectedStation)
        && $attempt === 1) {
        return 'Rejected';
    }

    return $attempt <= $pendingBootCount ? 'Pending' : 'Accepted';
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
