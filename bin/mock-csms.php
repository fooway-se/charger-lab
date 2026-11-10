<?php

declare(strict_types=1);

/**
 * Serves a local OCPP 2.0.1 WebSocket endpoint for emulator development.
 */
$port = (int) (getenv('MOCK_CSMS_PORT') ?: 9000);
$server = stream_socket_server("tcp://0.0.0.0:{$port}", $errorNumber, $errorMessage);
if (!is_resource($server)) {
    fwrite(STDERR, "Could not start mock CSMS: {$errorMessage}\n");
    exit(1);
}

fwrite(STDOUT, "Mock CSMS listening on 0.0.0.0:{$port} (ocpp2.0.1)\n");

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
        fwrite(STDERR, 'Mock CSMS client error: ' . $exception->getMessage() . "\n");
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

    $stationKey = acceptWebSocketUpgrade($client, $requestLine);
    if ($stationKey === null) {
        return;
    }

    serveOcppSession($client, $requestLine, $stationKey);
}

function acceptWebSocketUpgrade($client, string $requestLine): ?string
{
    $headers = [];
    $requestParts = explode(' ', trim($requestLine));
    $stationKey = $requestParts[1] ?? '/';
    while (($line = fgets($client)) !== false && $line !== "\r\n" && $line !== "\n") {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
    }

    $pathSegments = explode('/', trim($stationKey, '/'));
    $stationIdentity = rawurldecode((string) end($pathSegments));
    $expectedAuthStation = getenv('MOCK_CSMS_BASIC_AUTH_STATION') ?: '';
    if ($stationIdentity === $expectedAuthStation && $expectedAuthStation !== '') {
        $expectedPassword = getenv('MOCK_CSMS_BASIC_AUTH_PASSWORD') ?: '';
        $expectedAuthorization = 'Basic ' . base64_encode($expectedAuthStation . ':' . $expectedPassword);
        if (($headers['authorization'] ?? null) !== $expectedAuthorization) {
            fwrite($client, "HTTP/1.1 401 Unauthorized\r\nWWW-Authenticate: Basic realm=\"OCPP\"\r\nConnection: close\r\n\r\n");
            return null;
        }
    }

    $key = $headers['sec-websocket-key'] ?? '';
    $protocols = array_map('trim', explode(',', $headers['sec-websocket-protocol'] ?? ''));
    if ($key === '' || !in_array('ocpp2.0.1', $protocols, true)) {
        fwrite($client, "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n");
        return null;
    }

    $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    $response = "HTTP/1.1 101 Switching Protocols\r\n"
        . "Upgrade: websocket\r\nConnection: Upgrade\r\n"
        . "Sec-WebSocket-Accept: {$accept}\r\n"
        . "Sec-WebSocket-Protocol: ocpp2.0.1\r\n\r\n";
    writeAll($client, $response);
    fwrite(STDOUT, 'Connected ' . trim($requestLine) . "\n");

    return $stationKey;
}

function serveOcppSession($client, string $requestLine, string $stationKey): void
{
    $session = new MockCsmsSession(
        $stationKey,
        getenv('MOCK_CSMS_BOOT_COUNT_PATH') ?: '',
        (int) (getenv('MOCK_CSMS_PENDING_BOOT_COUNT') ?: 0),
    );
    while (($stationCall = readStationCall($client, $requestLine)) !== null) {
        [$payload, $call] = $stationCall;
        handleStationCall($client, $payload, $call, $session);
    }
}

function handleStationCall($client, string $payload, array $call, MockCsmsSession $session): void
{
    $action = $call[2] ?? '';
    if ($action === 'TransactionEvent' && (($call[3]['triggerReason'] ?? null) === 'MeterValueClock')) {
        fwrite(STDOUT, 'MeterValueClock TransactionEvent: ' . $payload . "\n");
    }
    $bootStatus = $action === 'BootNotification'
        ? nextBootStatus($session->bootNotifications, $session->pendingBootCount, $session->bootCountPath, $session->stationKey)
        : null;
    $result = [];
    if (!in_array($action, ['BootNotification', 'Authorize', 'Heartbeat', 'MeterValues', 'StatusNotification', 'TransactionEvent', 'SecurityEventNotification', 'FirmwareStatusNotification'], true)) {
        $replyFrame = [4, $call[1], 'NotImplemented', 'Unsupported action: ' . (string) $action, new stdClass()];
    } else {
        if ($action === 'SecurityEventNotification') {
            fwrite(STDOUT, 'SecurityEventNotification CALL: ' . $payload . "\n");
        }
        $result = match ($action) {
            'BootNotification' => [
                'status' => $bootStatus,
                'currentTime' => gmdate('Y-m-d\TH:i:s\Z'),
                'interval' => (int) ($bootStatus === 'Pending'
                    ? (getenv('MOCK_CSMS_PENDING_BOOT_INTERVAL') ?: getenv('MOCK_CSMS_BOOT_INTERVAL') ?: 300)
                    : (getenv('MOCK_CSMS_HEARTBEAT_INTERVAL') ?: 300)),
            ],
            'Authorize' => ['idTokenInfo' => ['status' => 'Accepted']],
            'Heartbeat' => stationCallResult('Heartbeat'),
            'MeterValues' => new stdClass(),
            'StatusNotification' => new stdClass(),
            'TransactionEvent' => stationCallResult('TransactionEvent', $call[3] ?? []),
            'SecurityEventNotification' => new stdClass(),
            'FirmwareStatusNotification' => new stdClass(),
        };
        $replyFrame = [3, $call[1], $result];
    }

    $reply = json_encode($replyFrame, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    fwrite(STDOUT, "OCPP out: {$reply}\n");
    if ($action === 'MeterValues') {
        fwrite(STDOUT, 'MeterValues response: ' . $reply . "\n");
    }
    if (getenv('MOCK_CSMS_FRAGMENT_RESPONSE') === '1') {
        $splitAt = max(1, intdiv(strlen($reply), 2));
        writeFrame($client, 0x1, substr($reply, 0, $splitAt), false);
        writeFrame($client, 0x0, substr($reply, $splitAt));
    } else {
        writeFrame($client, 0x1, $reply);
    }

    if ($action === 'BootNotification') {
        sendBaseReportAfterBoot($client, $call, $result, $session);
        sendBootTriggerMessage($client, $call, $result);
        sendDataTransferAfterBoot($client, $call, $result);
        sendResetAfterBoot($client, $call, $result);
        changeAvailabilityAfterBoot($client, $call, $result, $session);
        runConfiguredServerCalls($client, $call, $result, $session);
        sendCallAfterRejectedBoot($client, $call, $result);
    }

    if ($action === 'TransactionEvent') {
        triggerTransactionEventAfterStart($client, $payload, $call, $session);
    }
}

function sendDataTransferAfterBoot($client, array $call, array $result): void
{
    if (($result['status'] ?? null) !== 'Accepted' || getenv('MOCK_CSMS_SEND_DATA_TRANSFER') !== '1') {
        return;
    }

    $messageId = 'mock-csms-data-transfer-' . $call[1];
    $request = json_encode([2, $messageId, 'DataTransfer', [
        'vendorId' => 'com.example.unknown',
        'messageId' => 'UnsupportedCommand',
    ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    writeFrame($client, 0x1, $request);
    waitForStationResponse($client, $messageId, 'DataTransfer station response');
}

function sendBaseReportAfterBoot($client, array $call, array $result, MockCsmsSession $session): void
{
    if (($result['status'] ?? null) !== 'Accepted'
        || $session->baseReportSent
        || getenv('MOCK_CSMS_SEND_BASE_REPORT') !== '1') {
        return;
    }

    $session->baseReportSent = true;
    $messageId = 'mock-csms-base-report-' . $call[1];
    $request = json_encode([2, $messageId, 'GetBaseReport', [
        'requestId' => 71,
        'reportBase' => 'FullInventory',
    ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    fwrite(STDOUT, 'GetBaseReport CSMS call: ' . $request . "\n");
    writeFrame($client, 0x1, $request);
    $requestAccepted = false;
    $reportReceived = false;

    while (!$requestAccepted || !$reportReceived) {
        $response = readOcppMessage($client);
        if ($response === null) {
            return;
        }

        [$payload, $message] = $response;
        if (($message[0] ?? null) === 3 && ($message[1] ?? null) === $messageId) {
            fwrite(STDOUT, 'GetBaseReport station response: ' . $payload . "\n");
            $requestAccepted = ($message[2]['status'] ?? null) === 'Accepted';
            if (!$requestAccepted) {
                return;
            }
        } elseif (($message[0] ?? null) === 2 && ($message[2] ?? null) === 'NotifyReport') {
            fwrite(STDOUT, 'NotifyReport station call: ' . $payload . "\n");
            $reportReceived = ($message[3]['requestId'] ?? null) === 71
                && ($message[3]['tbc'] ?? null) === false
                && is_array($message[3]['reportData'] ?? null);
            sendStationCallResult($client, $message[1], new stdClass());
        } elseif (($message[0] ?? null) === 2) {
            sendStationCallResult($client, $message[1], stationCallResult($message[2] ?? '', $message[3] ?? []));
        }
    }
}


function sendBootTriggerMessage($client, array $call, array $result): void
{
    if (($result['status'] ?? null) === 'Accepted'
        && getenv('MOCK_CSMS_SEND_TRIGGER_MESSAGE') === '1') {
        $requestedMessage = getenv('MOCK_CSMS_TRIGGER_MESSAGE') ?: 'StatusNotification';
        $expectedStatusNotifications = (int) (getenv('MOCK_CSMS_TRIGGER_EXPECTED_STATUSES') ?: 1);
        $expectedInitialStatuses = (int) (getenv('MOCK_CSMS_INITIAL_STATUS_COUNT') ?: $expectedStatusNotifications);
        $initialStatusNotifications = 0;
        while ($initialStatusNotifications < $expectedInitialStatuses
            && ($stationMessageFrame = readOcppMessage($client)) !== null) {
            [, $stationMessage] = $stationMessageFrame;

            if (($stationMessage[0] ?? null) === 2 && ($stationMessage[2] ?? null) === 'StatusNotification') {
                $initialStatusNotifications++;
                sendStationCallResult($client, $stationMessage[1], new stdClass());
            } elseif (($stationMessage[0] ?? null) === 2) {
                respondToStationCall($client, $stationMessage);
            }
        }

        $triggerCallId = 'mock-csms-trigger-message-' . $call[1];
        $triggerPayload = ['requestedMessage' => $requestedMessage];
        if ($requestedMessage === 'Heartbeat' && getenv('MOCK_CSMS_TRIGGER_INCLUDE_EVSE') === '1') {
            $triggerPayload['evse'] = ['id' => 99];
        }

        $triggerCall = json_encode([2, $triggerCallId, 'TriggerMessage', $triggerPayload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        fwrite(STDOUT, 'TriggerMessage CSMS call: ' . $triggerCall . "\n");
        writeFrame($client, 0x1, $triggerCall);
        $triggerResponseReceived = false;
        $triggeredMessagesReceived = 0;
        $expectedTriggeredMessages = match ($requestedMessage) {
            'StatusNotification' => $expectedStatusNotifications,
            'MeterValues' => (int) (getenv('MOCK_CSMS_TRIGGER_EXPECTED_METER_VALUES') ?: 1),
            default => 1,
        };
        while (!$triggerResponseReceived || $triggeredMessagesReceived < $expectedTriggeredMessages) {
            $stationResponse = readOcppMessage($client);
            if ($stationResponse === null) {
                break;
            }
            [$stationResponsePayload, $stationMessage] = $stationResponse;

            if (in_array($stationMessage[0] ?? null, [3, 4], true)
                && ($stationMessage[1] ?? null) === $triggerCallId) {
                fwrite(STDOUT, 'TriggerMessage station response: ' . $stationResponsePayload . "\n");
                $triggerResponseReceived = true;
                if (($stationMessage[0] ?? null) === 4) {
                    break;
                }

                continue;
            }

            if (($stationMessage[0] ?? null) === 2 && ($stationMessage[2] ?? null) === $requestedMessage) {
                $triggeredMessageLabel = match ($requestedMessage) {
                    'StatusNotification' => 'TriggerMessage status notification: ',
                    'MeterValues' => 'TriggerMessage MeterValues: ',
                'FirmwareStatusNotification' => 'TriggerMessage FirmwareStatusNotification: ',
                    default => 'TriggerMessage Heartbeat: ',
                };
                fwrite(STDOUT, $triggeredMessageLabel . $stationResponsePayload . "\n");
                $triggeredMessagesReceived++;
                respondToStationCall($client, $stationMessage);
            } elseif (($stationMessage[0] ?? null) === 2) {
                respondToStationCall($client, $stationMessage);
            }
        }
    }
}

function sendResetAfterBoot($client, array $call, array $result): void
{
    if (($result['status'] ?? null) === 'Accepted'
        && getenv('MOCK_CSMS_SEND_RESET') === '1'
        && (($resetSentPath = getenv('MOCK_CSMS_RESET_SENT_PATH') ?: '') === '' || !is_file($resetSentPath))) {
        if (getenv('MOCK_CSMS_SEND_NETWORK_PROFILE') === '1') {
            configureNetworkProfile($client, $call[1]);
        }

        markResetSent($resetSentPath);
        sendResetCall($client, 'mock-csms-reset-' . $call[1], getenv('MOCK_CSMS_RESET_TYPE') ?: 'Immediate');
    }
}

function configureNetworkProfile($client, string $bootMessageId): void
{
    $profileUrl = getenv('MOCK_CSMS_NETWORK_PROFILE_URL') ?: '';
    $profileCallId = 'mock-csms-network-profile-' . $bootMessageId;
    $profileCall = json_encode([2, $profileCallId, 'SetNetworkProfile', [
        'configurationSlot' => 1,
        'connectionData' => [
            'ocppVersion' => 'OCPP20',
            'ocppTransport' => 'JSON',
            'ocppCsmsUrl' => $profileUrl,
            'messageTimeout' => 8,
            'securityProfile' => 1,
            'ocppInterface' => 'Wired0',
        ],
    ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    writeFrame($client, 0x1, $profileCall);
    waitForStationResponse($client, $profileCallId, 'SetNetworkProfile station response');

    $priorityCallId = 'mock-csms-network-priority-' . $bootMessageId;
    $priorityCall = json_encode([2, $priorityCallId, 'SetVariables', [
        'setVariableData' => [[
            'component' => ['name' => 'OCPPCommCtrlr'],
            'variable' => ['name' => 'NetworkConfigurationPriority'],
            'attributeType' => 'Actual',
            'attributeValue' => getenv('MOCK_CSMS_NETWORK_PRIORITY') ?: '1,0',
        ]],
    ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    writeFrame($client, 0x1, $priorityCall);
    waitForStationResponse($client, $priorityCallId, 'Network priority station response');

    $retrySettingsCallId = 'mock-csms-network-retry-settings-' . $bootMessageId;
    $retrySettingsCall = json_encode([2, $retrySettingsCallId, 'SetVariables', [
        'setVariableData' => [[
            'component' => ['name' => 'OCPPCommCtrlr'],
            'variable' => ['name' => 'NetworkProfileConnectionAttempts'],
            'attributeType' => 'Actual',
            'attributeValue' => getenv('MOCK_CSMS_NETWORK_PROFILE_ATTEMPTS') ?: '3',
        ], [
            'component' => ['name' => 'OCPPCommCtrlr'],
            'variable' => ['name' => 'RetryBackOffWaitMinimum'],
            'attributeType' => 'Actual',
            'attributeValue' => '1',
        ], [
            'component' => ['name' => 'OCPPCommCtrlr'],
            'variable' => ['name' => 'RetryBackOffRandomRange'],
            'attributeType' => 'Actual',
            'attributeValue' => '0',
        ]],
    ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    writeFrame($client, 0x1, $retrySettingsCall);
    waitForStationResponse($client, $retrySettingsCallId, 'Network retry settings station response');
}

function triggerTransactionEventAfterStart($client, string $payload, array $call, MockCsmsSession $session): void
{
    if (($call[3]['eventType'] ?? null) === 'Started'
        && !$session->triggerTransactionEventSent
        && getenv('MOCK_CSMS_SEND_TRIGGER_TRANSACTION_EVENT') === '1') {
        $session->triggerTransactionEventSent = true;
        $triggerCallId = 'mock-csms-trigger-transaction-event-' . $call[1];
        $triggerCall = json_encode([2, $triggerCallId, 'TriggerMessage', [
            'requestedMessage' => 'TransactionEvent',
            'evse' => ['id' => 1, 'connectorId' => 1],
        ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        fwrite(STDOUT, 'TriggerMessage CSMS call: ' . $triggerCall . "\n");
        writeFrame($client, 0x1, $triggerCall);
        $triggerResponseReceived = false;
        $triggeredEventReceived = false;
        while (!$triggerResponseReceived || !$triggeredEventReceived) {
            $stationMessageFrame = readOcppMessage($client);
            if ($stationMessageFrame === null) {
                break;
            }
            [$stationMessagePayload, $stationMessage] = $stationMessageFrame;

            if (in_array($stationMessage[0] ?? null, [3, 4], true)
                && ($stationMessage[1] ?? null) === $triggerCallId) {
                fwrite(STDOUT, 'TriggerMessage station response: ' . $stationMessagePayload . "\n");
                $triggerResponseReceived = true;
                if (($stationMessage[0] ?? null) === 4) {
                    break;
                }

                continue;
            }

            if (($stationMessage[0] ?? null) === 2 && ($stationMessage[2] ?? null) === 'TransactionEvent') {
                fwrite(STDOUT, 'TriggerMessage transaction event: ' . $stationMessagePayload . "\n");
                $payload = $stationMessage[3] ?? [];
                if (($payload['eventType'] ?? null) === 'Updated' && ($payload['triggerReason'] ?? null) === 'Trigger') {
                    $triggeredEventReceived = true;
                }

                sendStationCallResult($client, $stationMessage[1], new stdClass());
            } elseif (($stationMessage[0] ?? null) === 2 && ($stationMessage[2] ?? null) === 'StatusNotification') {
                sendStationCallResult($client, $stationMessage[1], new stdClass());
            }
        }
    }
}

function changeAvailabilityAfterBoot($client, array $call, array $result, MockCsmsSession $session): void
{
    if (($result['status'] ?? null) === 'Accepted'
        && !$session->changeAvailabilitySent
        && getenv('MOCK_CSMS_SEND_CHANGE_AVAILABILITY') === '1') {
        $session->changeAvailabilitySent = true;
        $changeAvailabilityCallId = 'mock-csms-change-availability-' . $call[1];
        $changeAvailabilityCall = json_encode([2, $changeAvailabilityCallId, 'ChangeAvailability', [
            'evse' => ['id' => 1],
            'operationalStatus' => 'Inoperative',
        ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        writeFrame($client, 0x1, $changeAvailabilityCall);
        $requestAnswered = false;
        $statusNotificationReceived = false;
        while ((!$requestAnswered || !$statusNotificationReceived)
            && ($stationResponse = readOcppMessage($client)) !== null) {
            [$stationResponsePayload, $stationMessage] = $stationResponse;

            if (in_array($stationMessage[0] ?? null, [3, 4], true)
                && ($stationMessage[1] ?? null) === $changeAvailabilityCallId) {
                fwrite(STDOUT, 'ChangeAvailability station response: ' . $stationResponsePayload . "\n");
                $requestAnswered = ($stationMessage[2]['status'] ?? null) === 'Accepted';
            } elseif (($stationMessage[0] ?? null) === 2 && ($stationMessage[2] ?? null) === 'StatusNotification') {
                $statusPayload = $stationMessage[3] ?? [];
                if (($statusPayload['connectorStatus'] ?? null) === 'Unavailable') {
                    fwrite(STDOUT, 'ChangeAvailability status notification: ' . $stationResponsePayload . "\n");
                    $statusNotificationReceived = true;
                }

                sendStationCallResult($client, $stationMessage[1], new stdClass());
            } elseif (($stationMessage[0] ?? null) === 2) {
                respondToStationCall($client, $stationMessage);
            }
        }
    }
}

function runConfiguredServerCalls($client, array $call, array $result, MockCsmsSession $session): void
{
    if (($result['status'] ?? null) !== 'Accepted'
        || $session->serverCallSent
        || getenv('MOCK_CSMS_SEND_CALL') !== '1') {
        return;
    }

    $session->serverCallSent = true;
    $bootCallId = $call[1];
    requestLocalListVersion($client, $bootCallId);
    sendLocalAuthorizationList($client, $bootCallId);
    configureRemoteStartAuthorization($client, $bootCallId);
    configureMeterTestSettings($client, $bootCallId);
    runRemoteStartScenario($client, $call, $session);
}

function requestLocalListVersion($client, string $bootCallId): void
{
    if (getenv('MOCK_CSMS_SEND_LOCAL_LIST_VERSION') !== '1') {
        return;
    }

    $messageId = 'mock-csms-local-list-version-' . $bootCallId;
    $call = json_encode([2, $messageId, 'GetLocalListVersion', new stdClass()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    writeFrame($client, 0x1, $call);
    waitForStationResponse($client, $messageId, 'GetLocalListVersion station response');
}

function sendLocalAuthorizationList($client, string $bootCallId): void
{
    if (getenv('MOCK_CSMS_SEND_LOCAL_LIST') !== '1') {
        return;
    }

    $messageId = 'mock-csms-send-local-list-' . $bootCallId;
    $call = json_encode([2, $messageId, 'SendLocalList', [
        'versionNumber' => 1,
        'updateType' => 'Full',
        'localAuthorizationList' => [[
            'idToken' => ['idToken' => 'MOCK-LOCAL-1', 'type' => 'ISO14443'],
            'idTokenInfo' => ['status' => 'Accepted'],
        ]],
    ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    writeFrame($client, 0x1, $call);
    waitForStationResponse($client, $messageId, 'SendLocalList station response');

    $versionCallId = 'mock-csms-local-list-version-after-update-' . $bootCallId;
    $versionCall = json_encode([2, $versionCallId, 'GetLocalListVersion', new stdClass()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    writeFrame($client, 0x1, $versionCall);
    waitForStationResponse($client, $versionCallId, 'GetLocalListVersion after SendLocalList station response');
}

function configureRemoteStartAuthorization($client, string $bootCallId): void
{
    $serverCallId = 'mock-csms-call-' . $bootCallId;
    $serverCall = json_encode([2, $serverCallId, 'GetVariables', [
        'getVariableData' => [[
            'component' => ['name' => 'AuthCtrlr'],
            'variable' => ['name' => 'AuthorizeRemoteStart'],
        ]],
    ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    writeFrame($client, 0x1, $serverCall);
    waitForStationResponse($client, $serverCallId, 'Station response');

    $setVariableCallId = 'mock-csms-set-call-' . $bootCallId;
    $setVariableCall = json_encode([2, $setVariableCallId, 'SetVariables', [
        'setVariableData' => [[
            'component' => ['name' => 'AuthCtrlr'],
            'variable' => ['name' => 'AuthorizeRemoteStart'],
            'attributeType' => 'Actual',
            'attributeValue' => 'true',
        ]],
    ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    writeFrame($client, 0x1, $setVariableCall);
    waitForStationResponse($client, $setVariableCallId, 'SetVariables station response');
}

function configureMeterTestSettings($client, string $bootCallId): void
{
    if (getenv('MOCK_CSMS_SET_METER_SETTINGS') === '1') {
        $meterSettingsCallId = 'mock-csms-meter-settings-' . $bootCallId;
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
            ], [
                'component' => ['name' => 'OCPPCommCtrlr'],
                'variable' => ['name' => 'RetryBackOffWaitMinimum'],
                'attributeValue' => '1',
            ], [
                'component' => ['name' => 'OCPPCommCtrlr'],
                'variable' => ['name' => 'RetryBackOffRandomRange'],
                'attributeValue' => '0',
            ], [
                'component' => ['name' => 'OCPPCommCtrlr'],
                'variable' => ['name' => 'RetryBackOffRepeatTimes'],
                'attributeValue' => '0',
            ]],
        ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        writeFrame($client, 0x1, $meterSettingsCall);
        waitForStationResponse($client, $meterSettingsCallId, 'Meter settings station response');
    }
}

function runRemoteStartScenario($client, array $call, MockCsmsSession $session): void
{
    $remoteStartSentPath = getenv('MOCK_CSMS_REMOTE_START_SENT_PATH') ?: '';
    if (getenv('MOCK_CSMS_SEND_REMOTE_START') === '1'
        && !$session->remoteStartSent
        && ($remoteStartSentPath === '' || !is_file($remoteStartSentPath))) {
        $session->remoteStartSent = true;
        if ($remoteStartSentPath !== '') {
            file_put_contents($remoteStartSentPath, 'sent', LOCK_EX);
        }

        $remoteTransactionId = sendRemoteStart($client, $call[1]);
        if (!is_string($remoteTransactionId)) {
            return;
        }

        sendAvailabilityChangeAfterRemoteStart($client, $call[1]);
        requestTransactionStatus($client, $call[1], $remoteTransactionId);
        resetAfterRemoteStart($client, $call[1]);
        waitForRemoteStartVehicle($client);
        sendRemoteStop($client, $call[1], $remoteTransactionId);
    }
}

function sendRemoteStart($client, string $bootCallId): ?string
{
    $messageId = 'mock-csms-remote-start-' . $bootCallId;
    $call = json_encode([2, $messageId, 'RequestStartTransaction', [
        'remoteStartId' => 42,
        'idToken' => ['idToken' => 'REMOTE-42', 'type' => 'ISO14443'],
        'evseId' => 1,
    ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    writeFrame($client, 0x1, $call);
    $requestAccepted = false;
    $transactionStarted = false;
    $transactionId = null;

    while (!$requestAccepted || !$transactionStarted) {
        $response = readOcppMessage($client);
        if ($response === null) {
            return null;
        }

        [$payload, $message] = $response;
        if (($message[0] ?? null) === 3 && ($message[1] ?? null) === $messageId) {
            fwrite(STDOUT, 'RemoteStart station response: ' . $payload . "\n");
            $requestAccepted = ($message[2]['status'] ?? null) === 'Accepted';
        } elseif (($message[0] ?? null) === 2) {
            $action = $message[2] ?? '';
            $request = $message[3] ?? [];
            if ($action === 'TransactionEvent'
                && ($request['eventType'] ?? null) === 'Started'
                && ($request['triggerReason'] ?? null) === 'RemoteStart') {
                $transactionStarted = true;
                $transactionId = $request['transactionInfo']['transactionId'] ?? null;
                fwrite(STDOUT, 'RemoteStart TransactionEvent: ' . $payload . "\n");
            }

            respondToStationCall($client, $message);
        }
    }

    if (getenv('MOCK_CSMS_SEND_UNLOCK_CONNECTOR') === '1') {
        $unlockCallId = 'mock-csms-unlock-connector-' . $bootCallId;
        $unlockCall = json_encode([2, $unlockCallId, 'UnlockConnector', [
            'evseId' => 1,
            'connectorId' => 1,
        ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        writeFrame($client, 0x1, $unlockCall);
        waitForStationResponse($client, $unlockCallId, 'UnlockConnector station response');
    }

    return is_string($transactionId) && $requestAccepted ? $transactionId : null;
}

function sendAvailabilityChangeAfterRemoteStart($client, string $bootCallId): void
{
    if (getenv('MOCK_CSMS_SEND_CHANGE_AFTER_REMOTE_START') !== '1') {
        return;
    }

    $messageId = 'mock-csms-pending-availability-' . $bootCallId;
    $call = json_encode([2, $messageId, 'ChangeAvailability', [
        'evse' => ['id' => 1],
        'operationalStatus' => 'Inoperative',
    ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    writeFrame($client, 0x1, $call);
    waitForStationResponse($client, $messageId, 'ChangeAvailability station response');
}

function requestTransactionStatus($client, string $bootCallId, string $transactionId): void
{
    $messageId = 'mock-csms-transaction-status-' . $bootCallId;
    $call = json_encode([2, $messageId, 'GetTransactionStatus', [
        'transactionId' => $transactionId,
    ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    writeFrame($client, 0x1, $call);
    waitForStationResponse($client, $messageId, 'GetTransactionStatus station response', [3]);
}

function resetAfterRemoteStart($client, string $bootCallId): void
{
    $path = getenv('MOCK_CSMS_RESET_SENT_PATH') ?: '';
    if (getenv('MOCK_CSMS_SEND_RESET_AFTER_REMOTE_START') !== '1'
        || ($path !== '' && is_file($path))) {
        return;
    }

    markResetSent($path);
    sendResetCall($client, 'mock-csms-reset-after-start-' . $bootCallId, getenv('MOCK_CSMS_RESET_TYPE') ?: 'OnIdle');
}

function waitForRemoteStartVehicle($client): void
{
    $continuePath = getenv('MOCK_CSMS_REMOTE_START_CONTINUE_PATH') ?: '';
    $stopBeforePlug = getenv('MOCK_CSMS_REMOTE_STOP_BEFORE_PLUG') === '1';
    if ($continuePath !== '' && !$stopBeforePlug) {
        waitForRemoteStartContinuation($client, $continuePath);
    }
}

function sendRemoteStop($client, string $bootCallId, string $transactionId): void
{
    $messageId = 'mock-csms-remote-stop-' . $bootCallId;
    $call = json_encode([2, $messageId, 'RequestStopTransaction', [
        'transactionId' => $transactionId,
    ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    writeFrame($client, 0x1, $call);
    $requestAccepted = false;
    $transactionEnded = false;
    $availabilityReported = getenv('MOCK_CSMS_SEND_CHANGE_AFTER_REMOTE_START') !== '1';
    $stopBeforePlug = getenv('MOCK_CSMS_REMOTE_STOP_BEFORE_PLUG') === '1';

    while (!$requestAccepted || !$transactionEnded || !$availabilityReported) {
        $response = readOcppMessage($client);
        if ($response === null) {
            return;
        }

        [$payload, $message] = $response;
        if (($message[0] ?? null) === 3 && ($message[1] ?? null) === $messageId) {
            fwrite(STDOUT, 'RemoteStop station response: ' . $payload . "\n");
            $requestAccepted = ($message[2]['status'] ?? null) === 'Accepted';
        } elseif (($message[0] ?? null) === 2) {
            $action = $message[2] ?? '';
            $request = $message[3] ?? [];
            if ($action === 'StatusNotification' && ($request['connectorStatus'] ?? null) === 'Unavailable') {
                fwrite(STDOUT, 'RemoteStop availability status: ' . $payload . "\n");
                $availabilityReported = true;
            }

            $transactionEnded = $action === 'TransactionEvent'
                && ($stopBeforePlug
                    ? (($request['eventType'] ?? null) === 'Ended'
                        && ($request['transactionInfo']['stoppedReason'] ?? null) === 'Remote')
                    : (($request['eventType'] ?? null) === 'Updated'
                        && ($request['triggerReason'] ?? null) === 'RemoteStop'));
            if ($transactionEnded) {
                fwrite(STDOUT, 'RemoteStop TransactionEvent: ' . $payload . "\n");
            }

            respondToStationCall($client, $message);
        }
    }
}


function sendCallAfterRejectedBoot($client, array $call, array $result): void
{
    if (($result['status'] ?? null) === 'Rejected'
        && getenv('MOCK_CSMS_SEND_REJECTED_CALL') === '1') {
        $serverCallId = 'mock-csms-rejected-call-' . $call[1];
        $serverCall = json_encode([2, $serverCallId, 'GetVariables', [
            'getVariableData' => [[
                'component' => ['name' => 'AuthCtrlr'],
                'variable' => ['name' => 'AuthorizeRemoteStart'],
            ]],
        ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        writeFrame($client, 0x1, $serverCall);
        waitForStationResponse($client, $serverCallId, 'Rejected station response', [4]);
    }
}

/**
 * Keeps per-connection counters and scenario flags for the mock CSMS.
 */
final class MockCsmsSession
{
    public int $bootNotifications = 0;

    public bool $serverCallSent = false;

    public bool $remoteStartSent = false;

    public bool $changeAvailabilitySent = false;

    public bool $triggerTransactionEventSent = false;

    public bool $baseReportSent = false;

    public function __construct(
        public readonly string $stationKey,
        public readonly string $bootCountPath,
        public readonly int $pendingBootCount,
    ) {
    }
}

function readStationCall($client, string $requestLine): ?array
{
    while (true) {
        $frame = readFrame($client);
        if ($frame === null) {
            fwrite(STDOUT, 'Disconnected ' . trim($requestLine) . "\n");
            return null;
        }

        [$opcode, $payload] = $frame;
        if ($opcode === 0x8) {
            writeFrame($client, 0x8, '');
            return null;
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

        return [$payload, $call];
    }
}

function waitForRemoteStartContinuation($client, string $continuePath): void
{
    while (!is_file($continuePath)) {
        $read = [$client];
        $write = [];
        $except = [];
        $ready = @stream_select($read, $write, $except, 0, 100_000);
        if ($ready === false) {
            throw new RuntimeException('Could not wait for the remote-start vehicle connection.');
        }
        if ($ready === 0) {
            continue;
        }

        $frame = readFrame($client);
        if ($frame === null) {
            return;
        }

        [$opcode, $payload] = $frame;
        if ($opcode === 0x9) {
            writeFrame($client, 0xA, $payload);
            continue;
        }

        $message = decodeStationMessage($frame);
        if ($message === null || ($message[0] ?? null) !== 2 || !is_string($message[1] ?? null)) {
            continue;
        }

        $action = $message[2] ?? '';
        $request = is_array($message[3] ?? null) ? $message[3] : [];
        if ($action === 'TransactionEvent' && ($request['triggerReason'] ?? null) === 'MeterValueClock') {
            fwrite(STDOUT, 'MeterValueClock TransactionEvent: ' . $payload . "\n");
        }
        respondToStationCall($client, $message);
    }
}

function readOcppMessage($client): ?array
{
    while (($frame = readFrame($client)) !== null) {
        $message = decodeStationMessage($frame);
        if ($message !== null) {
            return [$frame[1], $message];
        }
    }

    return null;
}

function decodeStationMessage(array $frame): ?array
{
    if ($frame[0] !== 0x1) {
        return null;
    }

    $message = json_decode($frame[1], true);

    return is_array($message) ? $message : null;
}

function stationCallResult(string $action, mixed $payload = null): array|stdClass
{
    return match ($action) {
        'Heartbeat' => ['currentTime' => gmdate('Y-m-d\\TH:i:s\\Z')],
        'TransactionEvent' => isset($payload['idToken'])
            ? ['idTokenInfo' => ['status' => 'Accepted']]
            : new stdClass(),
        default => new stdClass(),
    };
}

function waitForStationResponse($client, string $messageId, string $label, array $responseTypes = [3, 4]): void
{
    while (($response = readOcppMessage($client)) !== null) {
        [$payload, $message] = $response;
        if (in_array($message[0] ?? null, $responseTypes, true) && ($message[1] ?? null) === $messageId) {
            fwrite(STDOUT, $label . ': ' . $payload . "\n");

            return;
        }

        if (($message[0] ?? null) === 2) {
            respondToStationCall($client, $message);
        }
    }
}

function sendStationCallResult($client, string $messageId, array|stdClass $payload): void
{
    $response = json_encode([3, $messageId, $payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    writeFrame($client, 0x1, $response);
}

function respondToStationCall($client, array $message): void
{
    if (($message[2] ?? null) === 'SecurityEventNotification') {
        fwrite(STDOUT, 'SecurityEventNotification CALL: ' . json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    }

    sendStationCallResult(
        $client,
        $message[1],
        stationCallResult($message[2] ?? '', $message[3] ?? []),
    );
}

/**
 * @param resource $client
 */
function sendResetCall($client, string $messageId, string $type): void
{
    $call = json_encode([2, $messageId, 'Reset', ['type' => $type]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    writeFrame($client, 0x1, $call);
    waitForStationResponse($client, $messageId, 'Reset station response');
}

function markResetSent(string $path): void
{
    if ($path !== '') {
        file_put_contents($path, 'sent', LOCK_EX);
    }
}

function nextBootStatus(int &$bootNotifications, int $pendingBootCount, string $bootCountPath, string $stationKey): string
{
    $rejectedStation = getenv('MOCK_CSMS_REJECT_STATION') ?: '';
    if ($bootCountPath === '') {
        $bootNotifications++;
        $attempt = $bootNotifications;
    } else {
        $counter = fopen($bootCountPath, 'c+');
        if (!is_resource($counter)) {
            throw new RuntimeException('Could not lock the mock CSMS BootNotification counter.');
        }

        if (!flock($counter, LOCK_EX)) {
            fclose($counter);
            throw new RuntimeException('Could not lock the mock CSMS BootNotification counter.');
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
