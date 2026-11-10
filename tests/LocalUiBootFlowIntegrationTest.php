<?php

declare(strict_types=1);

use ChargerEmulator\Storage\TransactionEventOutboxRepository;

function findOpenPort(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
    expect(!is_resource($server))->toBeFalse('Could not reserve an integration-test port.');
    $address = stream_socket_get_name($server, false);
    fclose($server);
    expect(!is_string($address) || !str_contains($address, ':'))->toBeFalse('Could not determine the integration-test port.');

    return (int) substr(strrchr($address, ':'), 1);
}

function startProcess(array $command, array $environment)
{
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__), $environment);
    expect(!is_resource($process))->toBeFalse('Could not start an integration-test process.');
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $GLOBALS['testProcesses'][] = [$process, $pipes];

    return $process;
}

function waitUntilAvailable(int $port): void
{
    $deadline = microtime(true) + 4;
    while (microtime(true) < $deadline) {
        set_error_handler(static fn (): bool => true);
        try {
            $stream = stream_socket_client("tcp://127.0.0.1:{$port}", $errorNumber, $errorMessage, 0.1);
        } finally {
            restore_error_handler();
        }

        if (is_resource($stream)) {
            fclose($stream);
            return;
        }
        usleep(20_000);
    }

    throw new RuntimeException("Service on port {$port} did not start.");
}

function waitUntilDatabaseReady(string $databasePath): void
{
    $deadline = microtime(true) + 4;
    while (microtime(true) < $deadline) {
        if (is_file($databasePath)) {
            try {
                $database = new PDO('sqlite:' . $databasePath);
                $ready = $database->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'ocpp_command'")->fetchColumn();
                if ($ready !== false) {
                    return;
                }
            } catch (Throwable) {
            }
        }

        usleep(20_000);
    }

    throw new RuntimeException('The station session worker did not initialize its command queue.');
}

function waitForProcessOutput($process, string $expected, int $timeoutMilliseconds): string
{
    $deadline = microtime(true) + ($timeoutMilliseconds / 1000);
    $output = '';
    while (microtime(true) < $deadline) {
        foreach ($GLOBALS['testProcesses'] as [$candidate, $pipes]) {
            if ($candidate === $process) {
                $output .= stream_get_contents($pipes[1]);
                break;
            }
        }
        if (str_contains($output, $expected)) {
            $GLOBALS['capturedMockCsmsOutput'] = ($GLOBALS['capturedMockCsmsOutput'] ?? '') . $output;

            return $output;
        }

        usleep(20_000);
    }

    $GLOBALS['capturedMockCsmsOutput'] = ($GLOBALS['capturedMockCsmsOutput'] ?? '') . $output;

    return $output;
}

function drainProcessOutput($process): string
{
    foreach ($GLOBALS['testProcesses'] as [$candidate, $pipes]) {
        if ($candidate !== $process) {
            continue;
        }

        $output = stream_get_contents($pipes[1]);
        if (is_string($output) && $output !== '') {
            $GLOBALS['capturedMockCsmsOutput'] = ($GLOBALS['capturedMockCsmsOutput'] ?? '') . $output;

            return $output;
        }

        return '';
    }

    return '';
}

function waitForBootStatus(int $appPort, int $chargerId, string $status, int $timeoutMilliseconds): void
{
    $deadline = microtime(true) + ($timeoutMilliseconds / 1000);
    while (microtime(true) < $deadline) {
        [$body] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $state = is_string($body) ? json_decode($body, true, flags: JSON_THROW_ON_ERROR) : [];
        foreach ($state['chargers'] ?? [] as $charger) {
            if ((int) ($charger['id'] ?? 0) === $chargerId && ($charger['boot_status'] ?? null) === $status) {
                return;
            }
        }

        usleep(100_000);
    }

    throw new RuntimeException("The station did not reach BootNotification status {$status}.");
}

function waitForMeterValuePeriodic(int $appPort, int $chargerId, int $evseId, int $timeoutMilliseconds): ?array
{
    $deadline = microtime(true) + ($timeoutMilliseconds / 1000);
    while (microtime(true) < $deadline) {
        [$body] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $state = is_string($body) ? json_decode($body, true, flags: JSON_THROW_ON_ERROR) : [];
        foreach ($state['chargers'] ?? [] as $charger) {
            if ((int) ($charger['id'] ?? 0) !== $chargerId) {
                continue;
            }

            foreach ($charger['entries'] ?? [] as $entry) {
                if (($entry['action'] ?? null) !== 'TransactionEvent' || ($entry['direction'] ?? null) !== 'outgoing') {
                    continue;
                }

                $call = json_decode($entry['message'], true);
                if (($call[3]['triggerReason'] ?? null) === 'MeterValuePeriodic'
                    && ($call[3]['evse']['id'] ?? null) === $evseId) {
                    return $call[3];
                }
            }
        }

        usleep(20_000);
    }

    return null;
}

function waitForHeartbeat(int $appPort, int $chargerId, int $timeoutMilliseconds): array
{
    $deadline = microtime(true) + ($timeoutMilliseconds / 1000);
    do {
        [$body] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $state = is_string($body) ? json_decode($body, true, flags: JSON_THROW_ON_ERROR) : [];
        foreach ($state['chargers'] ?? [] as $charger) {
            if ((int) ($charger['id'] ?? 0) === $chargerId) {
                $heartbeats = array_values(array_filter(
                    $charger['entries'] ?? [],
                    static fn (array $entry): bool => ($entry['action'] ?? null) === 'Heartbeat',
                ));
                if (count($heartbeats) >= 2) {
                    return $heartbeats;
                }
            }
        }

        usleep(100_000);
    } while (microtime(true) < $deadline);

    return [];
}

function request(string $url, string $method = 'GET', string $content = '', string $contentType = 'application/x-www-form-urlencoded'): array
{
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => 'Content-Type: ' . $contentType,
        'content' => $content,
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 5,
    ]]);
    $body = file_get_contents($url, false, $context);

    return [$body, $http_response_header ?? []];
}

function stopProcess($process): void
{
    foreach ($GLOBALS['testProcesses'] ?? [] as [$candidate, $pipes]) {
        if ($candidate === $process) {
            proc_terminate($candidate);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($candidate);
        }
    }
}

it('boots multiple chargers and runs a plug authorize unplug flow', function (): void {
    $GLOBALS['capturedMockCsmsOutput'] = '';
    $GLOBALS['testProcesses'] = [];
    $directory = sys_get_temp_dir() . '/charger-ui-' . bin2hex(random_bytes(6));

    mkdir($directory, 0775, true);

    $databasePath = $directory . '/emulator.sqlite';

    $mockCsmsPort = findOpenPort();

    $appPort = findOpenPort();

    $overrideEndpoint = "ws://127.0.0.1:{$mockCsmsPort}/ocpp";

    $mockCsms = startProcess([PHP_BINARY, __DIR__ . '/../bin/mock-csms.php'], [
        'PATH' => (string) getenv('PATH'),
        'MOCK_CSMS_PORT' => (string) $mockCsmsPort,
        'MOCK_CSMS_HEARTBEAT_INTERVAL' => '1',
        'MOCK_CSMS_PENDING_BOOT_INTERVAL' => '6',
        'MOCK_CSMS_BOOT_COUNT_PATH' => $directory . '/mock-csms-boot-count',
        'MOCK_CSMS_PENDING_BOOT_COUNT' => '1',
        'MOCK_CSMS_REJECT_STATION' => 'UiIntegrationCharger',
        'MOCK_CSMS_BASIC_AUTH_STATION' => 'UiIntegrationCharger',
        'MOCK_CSMS_BASIC_AUTH_PASSWORD' => 'ui-station-secret',
        'MOCK_CSMS_SEND_CALL' => '1',
        'MOCK_CSMS_SEND_BASE_REPORT' => '1',
        'MOCK_CSMS_SEND_REJECTED_CALL' => '1',
        'MOCK_CSMS_SEND_REMOTE_START' => '1',
        'MOCK_CSMS_SEND_UNLOCK_CONNECTOR' => '1',
        'MOCK_CSMS_SEND_DATA_TRANSFER' => '1',
        'MOCK_CSMS_SEND_TRIGGER_MESSAGE' => '1',
        'MOCK_CSMS_TRIGGER_MESSAGE' => 'FirmwareStatusNotification',
        'MOCK_CSMS_SET_METER_SETTINGS' => '1',
        'MOCK_CSMS_REMOTE_START_SENT_PATH' => $directory . '/remote-start-sent',
        'MOCK_CSMS_REMOTE_START_CONTINUE_PATH' => $directory . '/remote-start-continue',
    ]);

    $worker = null;

    $app = null;

    try {
        waitUntilAvailable($mockCsmsPort);
        $worker = startProcess([PHP_BINARY, __DIR__ . '/../bin/station-worker.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilDatabaseReady($databasePath);
        $app = startProcess([PHP_BINARY, '-S', "127.0.0.1:{$appPort}", '-t', __DIR__ . '/../public', __DIR__ . '/../public/router.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilAvailable($appPort);

        [$page] = request("http://127.0.0.1:{$appPort}/");
        expect(!is_string($page) || !str_contains($page, 'Station simulator') || !str_contains($page, '/app.js'))->toBeFalse('The local app should serve the standalone station GUI.');

        [$initialStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $initialState = is_string($initialStateBody) ? json_decode($initialStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $chargerId = (int) ($initialState['chargers'][0]['id'] ?? 0);
        expect($chargerId < 1)->toBeFalse('The UI state should initialize a default charger.');

        [$createBody, $createHeaders] = request("http://127.0.0.1:{$appPort}/api/charger/create", 'POST', '{}', 'application/json');
        $createResponse = is_string($createBody) ? json_decode($createBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $secondChargerId = (int) ($createResponse['charger_id'] ?? 0);
        expect(!str_contains($createHeaders[0] ?? '', '201') || $secondChargerId < 1 || $secondChargerId === $chargerId)->toBeFalse('The local app should create an additional independent charger.');

        $workspaceUrl = 'wss://workspace-csms.example/ocpp';
        [$settingsBody, $settingsHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/settings/csms",
            'POST',
            json_encode(['default_csms_url' => $workspaceUrl], JSON_THROW_ON_ERROR),
            'application/json',
        );
        [$inheritedStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $inheritedState = is_string($inheritedStateBody) ? json_decode($inheritedStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        expect(!str_contains($settingsHeaders[0] ?? '', '200')
            || ($inheritedState['settings']['default_csms_url'] ?? null) !== $workspaceUrl
            || ($inheritedState['chargers'][0]['csms_url'] ?? null) !== $workspaceUrl
            || ($inheritedState['chargers'][1]['csms_url'] ?? null) !== $workspaceUrl)->toBeFalse('Changing the workspace CSMS default should update every charger that inherits it.');

        $payload = json_encode([
            'charger_id' => $chargerId,
            'uses_default_csms' => false,
            'station_identity' => 'UiIntegrationCharger',
            'csms_url' => $overrideEndpoint,
            'csms_password' => 'ui-station-secret',
            'vendor_name' => 'UI Test Vendor',
            'model' => 'UI Test Model',
            'serial_number' => 'UI-001',
            'outlet_count' => 2,
            'outlet_topology' => 'independent',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        [, $saveHeaders] = request("http://127.0.0.1:{$appPort}/api/charger", 'POST', $payload, 'application/json');
        expect(!str_contains($saveHeaders[0] ?? '', '200'))->toBeFalse('The charger configuration should save before testing its CSMS connection.');
        [$connectionTestBody, $connectionTestHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/connection/test",
            'POST',
            json_encode(['charger_id' => $chargerId], JSON_THROW_ON_ERROR),
            'application/json',
        );
        $connectionTest = is_string($connectionTestBody) ? json_decode($connectionTestBody, true, flags: JSON_THROW_ON_ERROR) : [];
        expect(!str_contains($connectionTestHeaders[0] ?? '', '200')
            || !str_contains($connectionTest['notice'] ?? '', 'WebSocket'))->toBeFalse('The setup screen should confirm the CSMS WebSocket without sending BootNotification.');
        [$preBootStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $preBootState = is_string($preBootStateBody) ? json_decode($preBootStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        expect($preBootState['chargers'][0]['entries'] ?? [])->toBeEmpty('Testing the connection should not add OCPP exchanges to the station log.');

        [$body, $headers] = request("http://127.0.0.1:{$appPort}/api/boot", 'POST', $payload, 'application/json');
        expect(!str_contains($headers[0] ?? '', '200') || !is_string($body))->toBeFalse('The BootNotification API should return an accepted CSMS response; received ' . ($headers[0] ?? 'no HTTP status') . ' ' . (string) $body);
        $bootResponse = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        expect(($bootResponse['result']['status'] ?? null) !== 'Rejected')->toBeFalse('The initial BootNotification API response should preserve the CSMS Rejected status.');

        [$pendingStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $pendingState = is_string($pendingStateBody) ? json_decode($pendingStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $pendingCharger = $pendingState['chargers'][0] ?? [];
        expect(($pendingCharger['has_password'] ?? false))->toBeTrue()
            ->and($pendingStateBody)->not->toContain('ui-station-secret');
        $rejectedCallOutput = waitForProcessOutput($mockCsms, 'Rejected station response: ', 1_000);
        expect($rejectedCallOutput)->toContain('"SecurityError"');
        $retryDeadline = strtotime((string) ($pendingCharger['boot_retry_at'] ?? ''));
        expect($retryDeadline)->toBeGreaterThan(time());

        stopProcess($worker);
        $worker = startProcess([PHP_BINARY, __DIR__ . '/../bin/station-worker.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        usleep(250_000);
        [$restartedStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $restartedState = is_string($restartedStateBody) ? json_decode($restartedStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $restartedCharger = $restartedState['chargers'][0] ?? [];
        $restartedBootCalls = array_filter(
            $restartedCharger['entries'] ?? [],
            static fn (array $entry): bool => ($entry['action'] ?? null) === 'BootNotification'
                && ($entry['direction'] ?? null) === 'outgoing',
        );
        expect($restartedCharger['boot_status'] ?? null)->toBe('Rejected');
        expect($restartedBootCalls)->toHaveCount(1);

        $pendingStatusCalls = array_filter($pendingCharger['entries'] ?? [], static fn (array $entry): bool => ($entry['action'] ?? null) === 'StatusNotification');
        $pendingStationCalls = array_filter(
            $pendingCharger['entries'] ?? [],
            static fn (array $entry): bool => ($entry['direction'] ?? null) === 'outgoing'
                && in_array($entry['action'] ?? null, ['Authorize', 'Heartbeat', 'StatusNotification', 'TransactionEvent'], true),
        );
        [, $pendingPlugHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/outlet/plug-in",
            'POST',
            json_encode(['charger_id' => $chargerId, 'outlet_number' => 1], JSON_THROW_ON_ERROR),
            'application/json',
        );
        [, $pendingAuthorizeHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/authorize",
            'POST',
            json_encode(['charger_id' => $chargerId, 'rfid_token' => '04A1B2C3D4'], JSON_THROW_ON_ERROR),
            'application/json',
        );
        expect(($pendingCharger['boot_status'] ?? null) !== 'Rejected'
            || ($pendingCharger['connection_status'] ?? null) !== 'Rejected'
            || !is_string($pendingCharger['boot_retry_at'] ?? null)
            || $pendingStatusCalls !== []
            || $pendingStationCalls !== []
            || !str_contains($pendingPlugHeaders[0] ?? '', '409')
            || !str_contains($pendingAuthorizeHeaders[0] ?? '', '409'))->toBeFalse('A Rejected station must send no station calls or vehicle actions before retry.');

        waitForBootStatus($appPort, $chargerId, 'Accepted', 8_000);
        $baseReportOutput = waitForProcessOutput($mockCsms, 'NotifyReport station call: ', 3_000);
        expect($baseReportOutput)->toContain('GetBaseReport station response: [3,"mock-csms-base-report-')
            ->toContain('"status":"Accepted"')
            ->toContain('NotifyReport station call: [2,')
            ->toContain('"requestId":71')
            ->toContain('"variableCharacteristics"');
        $baseReportResponsePosition = strpos($baseReportOutput, 'GetBaseReport station response:');
        $notifyReportPosition = strpos($baseReportOutput, 'NotifyReport station call:');
        expect($baseReportResponsePosition)->toBeInt()
            ->and($notifyReportPosition)->toBeInt()
            ->and($baseReportResponsePosition)->toBeLessThan($notifyReportPosition);
        [$bootStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $bootState = is_string($bootStateBody) ? json_decode($bootStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $statusEntries = array_values(array_filter(
            $bootState['chargers'][0]['entries'] ?? [],
            static function (array $entry): bool {
                if (($entry['action'] ?? null) !== 'StatusNotification' || ($entry['direction'] ?? null) !== 'outgoing') {
                    return false;
                }

                $call = json_decode($entry['message'], true);

                return ($call[3]['connectorStatus'] ?? null) === 'Available';
            },
        ));
        $statusCalls = array_values(array_filter(
            $statusEntries,
            static fn (array $entry): bool => ($entry['direction'] ?? null) === 'outgoing',
        ));
        $statusPayloads = array_map(
            static fn (array $entry): array => json_decode($entry['message'], true, flags: JSON_THROW_ON_ERROR)[3] ?? [],
            $statusCalls,
        );
        expect(count($statusCalls) !== 2
            || ($statusPayloads[0]['evseId'] ?? null) !== 1
            || ($statusPayloads[0]['connectorId'] ?? null) !== 1
            || ($statusPayloads[0]['connectorStatus'] ?? null) !== 'Available'
            || ($statusPayloads[1]['evseId'] ?? null) !== 2
            || ($statusPayloads[1]['connectorId'] ?? null) !== 1
            || ($statusPayloads[1]['connectorStatus'] ?? null) !== 'Available')->toBeFalse('An accepted charger boot should report Available for each configured outlet.');

        $incomingCallOutput = ($GLOBALS['capturedMockCsmsOutput'] ?? '')
            . waitForProcessOutput($mockCsms, 'Station response: ', 1_500);
        expect($incomingCallOutput)->toContain('"attributeValue":"false"');
        $setVariableOutput = waitForProcessOutput($mockCsms, 'SetVariables station response: ', 1_500);
        $capturedDeviceModelOutput = ($GLOBALS['capturedMockCsmsOutput'] ?? '') . $setVariableOutput;
        expect($capturedDeviceModelOutput)->toContain('"attributeStatus":"Rejected"');
        $meterSettingsOutput = (string) ($GLOBALS['capturedMockCsmsOutput'] ?? '');
        if (!str_contains($meterSettingsOutput, 'Meter settings station response: ')) {
            $meterSettingsOutput .= waitForProcessOutput($mockCsms, 'Meter settings station response: ', 5_000);
        }

        expect($meterSettingsOutput)->toContain('"attributeStatus":"Accepted"');
        $remoteStartOutput = (string) ($GLOBALS['capturedMockCsmsOutput'] ?? '');
        if (!str_contains($remoteStartOutput, 'RemoteStart station response: ')) {
            $remoteStartOutput .= waitForProcessOutput($mockCsms, 'RemoteStart station response: ', 5_000);
        }
        if (!str_contains($remoteStartOutput, 'RemoteStart TransactionEvent: ')) {
            $remoteStartOutput .= waitForProcessOutput($mockCsms, 'RemoteStart TransactionEvent: ', 5_000);
        }

        expect($remoteStartOutput)->toContain('"status":"Accepted"');
        expect(strpos($remoteStartOutput, 'RemoteStart station response: '))
            ->toBeLessThan(strpos($remoteStartOutput, 'RemoteStart TransactionEvent: '));
        if (!str_contains($remoteStartOutput, 'GetTransactionStatus station response: ')) {
            $remoteStartOutput .= waitForProcessOutput($mockCsms, 'GetTransactionStatus station response: ', 5_000);
        }
        expect($remoteStartOutput)->toContain('GetTransactionStatus station response: ')
            ->and($remoteStartOutput)->toContain('"ongoingIndicator":true,"messagesInQueue":false');
        $unlockOutput = (string) ($GLOBALS['capturedMockCsmsOutput'] ?? '');
        if (!str_contains($unlockOutput, 'UnlockConnector station response: ')) {
            $unlockOutput .= waitForProcessOutput($mockCsms, 'UnlockConnector station response: ', 3_000);
        }
        expect($unlockOutput)->toContain('"status":"OngoingAuthorizedTransaction"');
        $dataTransferOutput = (string) ($GLOBALS['capturedMockCsmsOutput'] ?? '');
        if (!str_contains($dataTransferOutput, 'DataTransfer station response: ')) {
            $dataTransferOutput .= waitForProcessOutput($mockCsms, 'DataTransfer station response: ', 3_000);
        }
        expect($dataTransferOutput)->toContain('"status":"UnknownVendorId"');
        $securityEventOutput = (string) ($GLOBALS['capturedMockCsmsOutput'] ?? '');
        if (!str_contains($securityEventOutput, 'SecurityEventNotification CALL: ')) {
            $securityEventOutput .= waitForProcessOutput($mockCsms, 'SecurityEventNotification CALL: ', 3_000);
        }
        preg_match('/SecurityEventNotification CALL: (\[[^\r\n]+\])/', $securityEventOutput, $securityEventMatch);
        $securityEventCall = json_decode($securityEventMatch[1] ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        $securityEvent = $securityEventCall[3] ?? [];
        expect($securityEventCall[2] ?? null)->toBe('SecurityEventNotification')
            ->and($securityEvent['type'] ?? null)->toBe('StartupOfTheDevice')
            ->and(preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $securityEvent['timestamp'] ?? ''))->toBe(1);
        $firmwareStatusOutput = (string) ($GLOBALS['capturedMockCsmsOutput'] ?? '');
        if (!str_contains($firmwareStatusOutput, 'TriggerMessage FirmwareStatusNotification: ')) {
            $firmwareStatusOutput .= waitForProcessOutput($mockCsms, 'TriggerMessage FirmwareStatusNotification: ', 3_000);
        }
        preg_match('/TriggerMessage FirmwareStatusNotification: (\[[^\r\n]+\])/', $firmwareStatusOutput, $firmwareStatusMatch);
        $firmwareStatusCall = json_decode($firmwareStatusMatch[1] ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        expect($firmwareStatusCall[2] ?? null)->toBe('FirmwareStatusNotification')
            ->and($firmwareStatusCall[3]['status'] ?? null)->toBe('Idle')
            ->and(array_key_exists('requestId', $firmwareStatusCall[3] ?? []))->toBeFalse();
        [$remotePendingStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $remotePendingState = is_string($remotePendingStateBody) ? json_decode($remotePendingStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $remotePendingOutlet = $remotePendingState['chargers'][0]['outlets'][0] ?? [];
        expect($remotePendingOutlet['remote_start_pending'] ?? false)->toBeTrue('An accepted remote start without a plugged-in vehicle should wait for the vehicle instead of simulating a plug-in.');
        $remoteStartEntry = array_values(array_filter(
            $remotePendingState['chargers'][0]['entries'] ?? [],
            static function (array $entry): bool {
                if (($entry['action'] ?? null) !== 'TransactionEvent' || ($entry['direction'] ?? null) !== 'outgoing') {
                    return false;
                }

                $call = json_decode($entry['message'], true);

                return ($call[3]['triggerReason'] ?? null) === 'RemoteStart';
            },
        ));
        $remoteStartPayload = json_decode($remoteStartEntry[0]['message'] ?? 'null', true);
        expect(isset($remoteStartPayload[3]['transactionInfo']['chargingState']))->toBeFalse('RemoteStart must not report Charging before a vehicle is physically connected.');
        $remoteConnectedEntries = array_values(array_filter(
            $remotePendingState['chargers'][0]['entries'] ?? [],
            static function (array $entry): bool {
                if (($entry['action'] ?? null) !== 'TransactionEvent' || ($entry['direction'] ?? null) !== 'outgoing') {
                    return false;
                }

                $call = json_decode($entry['message'], true);

                return ($call[3]['triggerReason'] ?? null) === 'CablePluggedIn';
            },
        ));
        expect($remoteConnectedEntries)->toBeEmpty('A remote-start transaction must not report the vehicle connected before the test-bench plug-in action.');
        [$remotePlugBody, $remotePlugHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/outlet/plug-in",
            'POST',
            json_encode(['charger_id' => $chargerId, 'outlet_number' => 1], JSON_THROW_ON_ERROR),
            'application/json',
        );
        expect(!str_contains($remotePlugHeaders[0] ?? '', '200') || !is_string($remotePlugBody))->toBeFalse('The test bench should connect a vehicle to the pending remote-start transaction.');
        [$connectedRemoteStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $connectedRemoteState = is_string($connectedRemoteStateBody) ? json_decode($connectedRemoteStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $connectedRemoteEvents = array_values(array_filter(
            $connectedRemoteState['chargers'][0]['entries'] ?? [],
            static function (array $entry): bool {
                if (($entry['action'] ?? null) !== 'TransactionEvent' || ($entry['direction'] ?? null) !== 'outgoing') {
                    return false;
                }

                $call = json_decode($entry['message'], true);

                return in_array($call[3]['triggerReason'] ?? null, ['CablePluggedIn', 'ChargingStateChanged'], true);
            },
        ));
        expect(count($connectedRemoteEvents))->toBe(2, 'Connecting the simulated vehicle should report EVConnected and only then Charging.');
        $connectedRemotePayloads = array_map(static fn (array $entry): array => json_decode($entry['message'], true)[3], $connectedRemoteEvents);
        expect(($connectedRemotePayloads[0]['transactionInfo']['chargingState'] ?? null))->toBe('EVConnected')
            ->and($connectedRemotePayloads[0]['seqNo'] ?? null)->toBe(1)
            ->and($connectedRemotePayloads[1]['transactionInfo']['chargingState'] ?? null)->toBe('Charging')
            ->and($connectedRemotePayloads[1]['seqNo'] ?? null)->toBe(2);
        file_put_contents($directory . '/remote-start-continue', 'connected', LOCK_EX);
        if (!str_contains($remoteStartOutput, 'RemoteStop station response: ')) {
            $remoteStartOutput .= waitForProcessOutput($mockCsms, 'RemoteStop station response: ', 5_000);
        }

        if (!str_contains($remoteStartOutput, 'RemoteStop TransactionEvent: ')) {
            $remoteStartOutput .= waitForProcessOutput($mockCsms, 'RemoteStop TransactionEvent: ', 5_000);
        }

        expect($remoteStartOutput)->toContain('RemoteStop station response: ');
        expect($remoteStartOutput)->toContain('"status":"Accepted"');
        expect(strpos($remoteStartOutput, 'RemoteStop station response: '))
            ->toBeLessThan(strpos($remoteStartOutput, 'RemoteStop TransactionEvent: '));

        [$remoteStopUnplugBody, $remoteStopUnplugHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/outlet/unplug",
            'POST',
            json_encode(['charger_id' => $chargerId, 'outlet_number' => 1], JSON_THROW_ON_ERROR),
            'application/json',
        );
        expect(!str_contains($remoteStopUnplugHeaders[0] ?? '', '200') || !is_string($remoteStopUnplugBody))->toBeFalse('The vehicle should unplug after the remote stop request has ended energy delivery.');
        $heartbeatEntries = waitForHeartbeat($appPort, $chargerId, 7_000);
        $heartbeatCall = json_decode($heartbeatEntries[0]['message'] ?? 'null', false);
        expect(count($heartbeatEntries) < 2
            || ($heartbeatEntries[0]['direction'] ?? null) !== 'outgoing'
            || ($heartbeatEntries[1]['direction'] ?? null) !== 'incoming'
            || !is_array($heartbeatCall)
            || !($heartbeatCall[3] ?? null) instanceof stdClass)->toBeFalse('Heartbeat exchange mismatch: ' . json_encode([
                'count' => count($heartbeatEntries),
                'directions' => array_column($heartbeatEntries, 'direction'),
                'payload' => $heartbeatCall[3] ?? null,
            ]));

        [$stateBody, $stateHeaders] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        expect(!str_contains($stateHeaders[0] ?? '', '200'))->toBeFalse('A bodyless state request should succeed even if the browser sends a JSON content type.');
        $state = is_string($stateBody) ? json_decode($stateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $charger = $state['chargers'][0] ?? [];
        $secondCharger = $state['chargers'][1] ?? [];
        $bootEntries = array_values(array_filter($charger['entries'] ?? [], static fn (array $entry): bool => ($entry['action'] ?? null) === 'BootNotification'));
        $heartbeatStateEntries = array_values(array_filter($charger['entries'] ?? [], static fn (array $entry): bool => ($entry['action'] ?? null) === 'Heartbeat'));
        $serverCallEntries = array_values(array_filter($charger['entries'] ?? [], static fn (array $entry): bool => ($entry['action'] ?? null) === 'GetVariables'));
        expect(count($state['chargers'] ?? []) !== 2
            || ($charger['boot_status'] ?? null) !== 'Accepted'
            || ($charger['connection_status'] ?? null) !== 'Connected'
            || count($bootEntries) < 2
            || count($heartbeatStateEntries) < 2
            || count($serverCallEntries) < 2
            || ($charger['outlets'][0]['evse_id'] ?? null) !== 1
            || ($charger['outlets'][0]['connector_id'] ?? null) !== 1
            || ($charger['outlets'][1]['evse_id'] ?? null) !== 2
            || ($charger['outlets'][1]['connector_id'] ?? null) !== 1
            || count($secondCharger['entries'] ?? []) !== 0
            || ($secondCharger['csms_url'] ?? null) !== $workspaceUrl
            || ($charger['csms_url'] ?? null) !== $overrideEndpoint)->toBeFalse('The local UI state should expose two independent EVSEs and the BootNotification log.');

        [$plugBody, $plugHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/outlet/plug-in",
            'POST',
            json_encode(['charger_id' => $chargerId, 'outlet_number' => 2], JSON_THROW_ON_ERROR),
            'application/json',
        );
        $plugResponse = is_string($plugBody) ? json_decode($plugBody, true, flags: JSON_THROW_ON_ERROR) : [];
        expect(!str_contains($plugHeaders[0] ?? '', '200')
            || ($plugResponse['result']['status'] ?? null) !== 'PluggedIn')->toBeFalse('Plugging in an outlet should report its occupied and transaction-start events.');
        [$duplicatePlugBody, $duplicatePlugHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/outlet/plug-in",
            'POST',
            json_encode(['charger_id' => $chargerId, 'outlet_number' => 2], JSON_THROW_ON_ERROR),
            'application/json',
        );
        [$pluggedStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $pluggedState = is_string($pluggedStateBody) ? json_decode($pluggedStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $pluggedOutlet = $pluggedState['chargers'][0]['outlets'][1] ?? [];
        $plugStatusCalls = array_values(array_filter(
            $pluggedState['chargers'][0]['entries'] ?? [],
            static fn (array $entry): bool => ($entry['action'] ?? null) === 'StatusNotification' && ($entry['direction'] ?? null) === 'outgoing',
        ));
        $lastPlugStatusCall = $plugStatusCalls === [] ? [] : $plugStatusCalls[array_key_last($plugStatusCalls)];
        $occupiedStatusPayload = json_decode($lastPlugStatusCall['message'] ?? 'null', true);
        expect(!str_contains($duplicatePlugHeaders[0] ?? '', '409')
            || ($pluggedOutlet['connector_status'] ?? null) !== 'Occupied'
            || !is_string($pluggedOutlet['transaction_id'] ?? null)
            || $pluggedOutlet['transaction_id'] === ''
            || ($occupiedStatusPayload[3]['connectorStatus'] ?? null) !== 'Occupied'
            || ($occupiedStatusPayload[3]['evseId'] ?? null) !== 2)->toBeFalse('An occupied outlet should retain one active transaction and reject a duplicate plug-in.');
        $activeConfiguration = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $activeConfiguration['outlet_count'] = 1;
        [, $activeConfigurationHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/charger",
            'POST',
            json_encode($activeConfiguration, JSON_THROW_ON_ERROR),
            'application/json',
        );
        [, $activeDeleteHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/charger/delete",
            'POST',
            json_encode(['charger_id' => $chargerId], JSON_THROW_ON_ERROR),
            'application/json',
        );
        expect(!str_contains($activeConfigurationHeaders[0] ?? '', '409')
            || !str_contains($activeDeleteHeaders[0] ?? '', '409'))->toBeFalse('An active transaction should prevent changing its outlet map or removing its charger.');

        $authorizePayload = json_encode([
            'charger_id' => $chargerId,
            'uses_default_csms' => false,
            'station_identity' => 'UiIntegrationCharger',
            'csms_url' => $overrideEndpoint,
            'vendor_name' => 'UI Test Vendor',
            'model' => 'UI Test Model',
            'serial_number' => 'UI-001',
            'outlet_count' => 2,
            'outlet_topology' => 'independent',
            'rfid_token' => '04A1B2C3D4',
            'outlet_number' => 2,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        [$authorizeBody, $authorizeHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/authorize",
            'POST',
            $authorizePayload,
            'application/json',
        );
        $authorizeResponse = is_string($authorizeBody) ? json_decode($authorizeBody, true, flags: JSON_THROW_ON_ERROR) : [];
        expect(!str_contains($authorizeHeaders[0] ?? '', '200')
            || ($authorizeResponse['result']['idTokenInfo']['status'] ?? null) !== 'Accepted')->toBeFalse('The RFID token form should send Authorize and show its Accepted response; received ' . ($authorizeHeaders[0] ?? 'no HTTP status') . ' ' . (string) $authorizeBody);

        $periodicMeterPayload = waitForMeterValuePeriodic($appPort, $chargerId, 2, 4_000);
        expect($periodicMeterPayload)->toBeArray()
            ->and($periodicMeterPayload['eventType'] ?? null)->toBe('Updated')
            ->and($periodicMeterPayload['seqNo'] ?? null)->toBe(3)
            ->and($periodicMeterPayload['meterValue'][0]['sampledValue'][0]['context'] ?? null)->toBe('Sample.Periodic')
            ->and($periodicMeterPayload['meterValue'][0]['sampledValue'][0]['measurand'] ?? null)->toBe('Energy.Active.Import.Register');

        [$updatedStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $updatedState = is_string($updatedStateBody) ? json_decode($updatedStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $updatedCharger = $updatedState['chargers'][0] ?? [];
        $updatedSecondCharger = $updatedState['chargers'][1] ?? [];
        $authorizeEntries = array_values(array_filter($updatedCharger['entries'] ?? [], static fn (array $entry): bool => ($entry['action'] ?? null) === 'Authorize' && ($entry['direction'] ?? null) === 'outgoing'));
        $authorizeCall = json_decode($authorizeEntries[0]['message'] ?? 'null', true);
        expect(count($authorizeEntries) !== 1
            || ($authorizeCall[3]['idToken']['idToken'] ?? null) !== '04A1B2C3D4'
            || ($authorizeCall[3]['idToken']['type'] ?? null) !== 'ISO14443'
            || count($updatedSecondCharger['entries'] ?? []) !== 0)->toBeFalse('The local log should persist the presented RFID token as an ISO14443 Authorize call.');

        $transactionEntries = array_values(array_filter(
            $updatedCharger['entries'] ?? [],
            static function (array $entry): bool {
                if (($entry['action'] ?? null) !== 'TransactionEvent') {
                    return false;
                }

                $call = json_decode($entry['message'], true);

                return ($call[3]['evse']['id'] ?? null) === 2;
            },
        ));
        $remoteStartEntries = array_values(array_filter(
            $updatedCharger['entries'] ?? [],
            static function (array $entry): bool {
                if (($entry['action'] ?? null) !== 'TransactionEvent' || ($entry['direction'] ?? null) !== 'outgoing') {
                    return false;
                }

                $call = json_decode($entry['message'], true);

                return ($call[3]['triggerReason'] ?? null) === 'RemoteStart';
            },
        ));
        $remoteStartPayload = json_decode($remoteStartEntries[0]['message'] ?? 'null', true);
        expect(count($remoteStartEntries) !== 1
            || ($remoteStartPayload[3]['eventType'] ?? null) !== 'Started'
            || ($remoteStartPayload[3]['transactionInfo']['remoteStartId'] ?? null) !== 42
            || ($remoteStartPayload[3]['idToken']['idToken'] ?? null) !== 'REMOTE-42'
            || isset($remoteStartPayload[3]['meterValue']))->toBeFalse('An accepted remote start should produce a correlated Started TransactionEvent without disabled start meter data.');
        $remoteStopEntries = array_values(array_filter(
            $updatedCharger['entries'] ?? [],
            static function (array $entry): bool {
                if (($entry['action'] ?? null) !== 'TransactionEvent' || ($entry['direction'] ?? null) !== 'outgoing') {
                    return false;
                }

                $call = json_decode($entry['message'], true);

                return ($call[3]['triggerReason'] ?? null) === 'RemoteStop';
            },
        ));
        $remoteStopPayload = json_decode($remoteStopEntries[0]['message'] ?? 'null', true);
        expect(count($remoteStopEntries) !== 1
            || ($remoteStopPayload[3]['eventType'] ?? null) !== 'Updated'
            || ($remoteStopPayload[3]['triggerReason'] ?? null) !== 'RemoteStop')->toBeFalse('An accepted remote stop should report the stop with a RemoteStop update before cable removal.');
        $remoteEndedEntries = array_values(array_filter(
            $updatedCharger['entries'] ?? [],
            static function (array $entry): bool {
                if (($entry['action'] ?? null) !== 'TransactionEvent' || ($entry['direction'] ?? null) !== 'outgoing') {
                    return false;
                }

                $call = json_decode($entry['message'], true);

                return ($call[3]['eventType'] ?? null) === 'Ended'
                    && ($call[3]['evse']['id'] ?? null) === 1;
            },
        ));
        $remoteEndedPayload = json_decode($remoteEndedEntries[0]['message'] ?? 'null', true);
        expect(count($remoteEndedEntries) !== 1
            || ($remoteEndedPayload[3]['transactionInfo']['stoppedReason'] ?? null) !== 'Remote')->toBeFalse('After unplugging, the stopped transaction should end with reason Remote.');
        $transactionCalls = array_values(array_filter(
            $transactionEntries,
            static fn (array $entry): bool => ($entry['direction'] ?? null) === 'outgoing',
        ));
        $startedPayload = json_decode($transactionCalls[0]['message'] ?? 'null', true);
        $authorizedPayload = json_decode($transactionCalls[1]['message'] ?? 'null', true);
        $chargingPayload = json_decode($transactionCalls[2]['message'] ?? 'null', true);
        expect(count($transactionCalls) < 4
            || ($startedPayload[3]['eventType'] ?? null) !== 'Started'
            || ($startedPayload[3]['triggerReason'] ?? null) !== 'CablePluggedIn'
            || ($startedPayload[3]['seqNo'] ?? null) !== 0
            || isset($startedPayload[3]['meterValue'])
            || ($startedPayload[3]['evse']['id'] ?? null) !== 2
            || ($startedPayload[3]['transactionInfo']['transactionId'] ?? null) !== $pluggedOutlet['transaction_id']
            || ($startedPayload[3]['transactionInfo']['chargingState'] ?? null) !== 'EVConnected'
            || ($authorizedPayload[3]['eventType'] ?? null) !== 'Updated'
            || ($authorizedPayload[3]['triggerReason'] ?? null) !== 'Authorized'
            || ($authorizedPayload[3]['seqNo'] ?? null) !== 1
            || ($authorizedPayload[3]['transactionInfo']['transactionId'] ?? null) !== $pluggedOutlet['transaction_id']
            || isset($authorizedPayload[3]['transactionInfo']['chargingState'])
            || ($authorizedPayload[3]['idToken']['idToken'] ?? null) !== '04A1B2C3D4'
            || ($chargingPayload[3]['eventType'] ?? null) !== 'Updated'
            || ($chargingPayload[3]['triggerReason'] ?? null) !== 'ChargingStateChanged'
            || ($chargingPayload[3]['seqNo'] ?? null) !== 2
            || ($chargingPayload[3]['transactionInfo']['chargingState'] ?? null) !== 'Charging'
            || ($transactionCalls[3]['direction'] ?? null) !== 'outgoing'
            || ($periodicMeterPayload['triggerReason'] ?? null) !== 'MeterValuePeriodic')->toBeFalse('The plug-first flow should start a transaction, attach its accepted RFID token, and send its configured periodic sample.');

        [$localStopBody, $localStopHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/authorize",
            'POST',
            $authorizePayload,
            'application/json',
        );
        $localStopResponse = is_string($localStopBody) ? json_decode($localStopBody, true, flags: JSON_THROW_ON_ERROR) : [];
        [$localStopStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $localStopState = is_string($localStopStateBody) ? json_decode($localStopStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $localStopCharger = $localStopState['chargers'][0] ?? [];
        $localStopOutlet = $localStopCharger['outlets'][1] ?? [];
        $localStopAuthorizes = array_values(array_filter(
            $localStopCharger['entries'] ?? [],
            static fn (array $entry): bool => ($entry['action'] ?? null) === 'Authorize'
                && ($entry['direction'] ?? null) === 'outgoing',
        ));
        $localStopEvents = array_values(array_filter(
            $localStopCharger['entries'] ?? [],
            static function (array $entry): bool {
                if (($entry['action'] ?? null) !== 'TransactionEvent' || ($entry['direction'] ?? null) !== 'outgoing') {
                    return false;
                }

                $call = json_decode($entry['message'], true);

                return ($call[3]['triggerReason'] ?? null) === 'StopAuthorized';
            },
        ));
        $localStopCall = json_decode($localStopEvents[0]['message'] ?? 'null', true);
        expect(!str_contains($localStopHeaders[0] ?? '', '200')
            || ($localStopResponse['result']['status'] ?? null) !== 'LocalStopRequested'
            || count($localStopAuthorizes) !== 1
            || count($localStopEvents) !== 1
            || ($localStopCall[3]['eventType'] ?? null) !== 'Updated'
            || ($localStopCall[3]['idToken']['idToken'] ?? null) !== '04A1B2C3D4'
            || ($localStopOutlet['connector_status'] ?? null) !== 'Occupied'
            || ($localStopOutlet['transaction_id'] ?? null) !== $pluggedOutlet['transaction_id']
            || ($localStopOutlet['is_charging'] ?? true))->toBeFalse('Presenting the same RFID during charging should stop energy without re-authorizing or ending the session before unplug.');

        [$unplugBody, $unplugHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/outlet/unplug",
            'POST',
            json_encode(['charger_id' => $chargerId, 'outlet_number' => 2], JSON_THROW_ON_ERROR),
            'application/json',
        );
        $unplugResponse = is_string($unplugBody) ? json_decode($unplugBody, true, flags: JSON_THROW_ON_ERROR) : [];
        [$unpluggedStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $unpluggedState = is_string($unpluggedStateBody) ? json_decode($unpluggedStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $finalTransactionCalls = array_values(array_filter(
            $unpluggedState['chargers'][0]['entries'] ?? [],
            static function (array $entry): bool {
                if (($entry['action'] ?? null) !== 'TransactionEvent' || ($entry['direction'] ?? null) !== 'outgoing') {
                    return false;
                }

                $call = json_decode($entry['message'], true);

                return ($call[3]['evse']['id'] ?? null) === 2;
            },
        ));
        $endedCall = null;
        foreach ($finalTransactionCalls as $transactionCall) {
            $payload = json_decode($transactionCall['message'], true);
            if (($payload[3]['eventType'] ?? null) === 'Ended') {
                $endedCall = $transactionCall;
            }
        }
        $endedPayload = is_array($endedCall) ? json_decode($endedCall['message'], true) : [];
        $periodicCalls = array_filter($finalTransactionCalls, static function (array $transactionCall): bool {
            $payload = json_decode($transactionCall['message'], true);

            return ($payload[3]['triggerReason'] ?? null) === 'MeterValuePeriodic';
        });
        $endedMeterValues = $endedPayload[3]['meterValue'] ?? [];
        $endedIntervalSamples = array_filter($endedMeterValues, static function (array $meterValue): bool {
            return ($meterValue['sampledValue'][0]['context'] ?? null) === 'Sample.Periodic';
        });
        $endedRegisterSamples = array_filter($endedMeterValues, static function (array $meterValue): bool {
            return ($meterValue['sampledValue'][0]['context'] ?? null) === 'Transaction.End';
        });
        $expectedEndedSequence = 4 + count($periodicCalls);
        $expectedEndedMeterWh = 1000 + (100 * (count($periodicCalls) + count($endedIntervalSamples)));
        $unplugDatabase = new PDO('sqlite:' . $databasePath);
        $persistedMeterWh = (int) $unplugDatabase->query(
            "SELECT meter_wh FROM outlet_session WHERE charger_id = {$chargerId} AND evse_id = 2 AND connector_id = 1",
        )->fetchColumn();
        unset($unplugDatabase);
        $finalStatusCalls = array_values(array_filter(
            $unpluggedState['chargers'][0]['entries'] ?? [],
            static fn (array $entry): bool => ($entry['action'] ?? null) === 'StatusNotification' && ($entry['direction'] ?? null) === 'outgoing',
        ));
        $lastAvailableCall = $finalStatusCalls === [] ? [] : $finalStatusCalls[array_key_last($finalStatusCalls)];
        $availableStatusPayload = json_decode($lastAvailableCall['message'] ?? 'null', true);
        expect(!str_contains($unplugHeaders[0] ?? '', '200')
            || ($unplugResponse['result']['status'] ?? null) !== 'Unplugged'
            || ($unpluggedState['chargers'][0]['outlets'][1]['connector_status'] ?? null) !== 'Available'
            || ($unpluggedState['chargers'][0]['outlets'][1]['transaction_id'] ?? null) !== null
            || ($endedPayload[3]['eventType'] ?? null) !== 'Ended'
            || ($endedPayload[3]['seqNo'] ?? null) !== $expectedEndedSequence
            || ($endedPayload[3]['transactionInfo']['transactionId'] ?? null) !== $pluggedOutlet['transaction_id']
            || ($endedPayload[3]['transactionInfo']['chargingState'] ?? null) !== 'Idle'
            || ($endedPayload[3]['transactionInfo']['stoppedReason'] ?? null) !== 'EVDisconnected'
            || $endedIntervalSamples === []
            || $endedRegisterSamples === []
            || $persistedMeterWh !== $expectedEndedMeterWh
            || ($availableStatusPayload[3]['connectorStatus'] ?? null) !== 'Available'
            || ($availableStatusPayload[3]['evseId'] ?? null) !== 2)->toBeFalse('Unplugging an outlet should end its transaction and restore Available status.');
        [, $duplicateUnplugHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/outlet/unplug",
            'POST',
            json_encode(['charger_id' => $chargerId, 'outlet_number' => 2], JSON_THROW_ON_ERROR),
            'application/json',
        );
        expect(!str_contains($duplicateUnplugHeaders[0] ?? '', '409'))->toBeFalse('Unplugging an already available outlet should return a conflict.');

        $updatedWorkspaceUrl = 'wss://second-workspace-csms.example/ocpp';
        [$updatedSettingsBody, $updatedSettingsHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/settings/csms",
            'POST',
            json_encode(['default_csms_url' => $updatedWorkspaceUrl], JSON_THROW_ON_ERROR),
            'application/json',
        );
        [$overrideStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $overrideState = is_string($overrideStateBody) ? json_decode($overrideStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        expect(!str_contains($updatedSettingsHeaders[0] ?? '', '200')
            || ($overrideState['chargers'][0]['csms_url'] ?? null) !== $overrideEndpoint
            || ($overrideState['chargers'][1]['csms_url'] ?? null) !== $updatedWorkspaceUrl)->toBeFalse('Changing the workspace default should leave a charger override unchanged.');

        request(
            "http://127.0.0.1:{$appPort}/api/settings/csms",
            'POST',
            json_encode(['default_csms_url' => $overrideEndpoint], JSON_THROW_ON_ERROR),
            'application/json',
        );
        [$secondBootBody, $secondBootHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/boot",
            'POST',
            json_encode([
                'charger_id' => $secondChargerId,
                'uses_default_csms' => true,
                'station_identity' => 'DemoCharger 2',
                'vendor_name' => 'Local Emulator',
                'model' => 'Developer Charger',
                'serial_number' => 'DEMO-002',
                'outlet_count' => 1,
                'outlet_topology' => 'independent',
            ], JSON_THROW_ON_ERROR),
            'application/json',
        );
        $secondBootResult = is_string($secondBootBody) ? json_decode($secondBootBody, true, flags: JSON_THROW_ON_ERROR) : [];
        expect(!str_contains($secondBootHeaders[0] ?? '', '200')
            || ($secondBootResult['result']['status'] ?? null) !== 'Pending')->toBeFalse('The session worker should keep multiple charger connections active against the mock CSMS.');
        waitForBootStatus($appPort, $secondChargerId, 'Accepted', 8_000);

        $sharedTopology = json_encode([
            'charger_id' => $chargerId,
            'uses_default_csms' => false,
            'station_identity' => 'UiIntegrationCharger',
            'csms_url' => $overrideEndpoint,
            'vendor_name' => 'UI Test Vendor',
            'model' => 'UI Test Model',
            'serial_number' => 'UI-001',
            'outlet_count' => 2,
            'outlet_topology' => 'shared',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        [$sharedBody, $sharedHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/charger",
            'POST',
            $sharedTopology,
            'application/json',
        );
        expect(!str_contains($sharedHeaders[0] ?? '', '200') || !is_string($sharedBody))->toBeFalse('The local API should save a shared EVSE topology.');
        [$sharedStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $sharedState = is_string($sharedStateBody) ? json_decode($sharedStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $sharedCharger = $sharedState['chargers'][0] ?? [];
        expect(($sharedCharger['outlets'][0]['evse_id'] ?? null) !== 1
            || ($sharedCharger['outlets'][0]['connector_id'] ?? null) !== 1
            || ($sharedCharger['outlets'][1]['evse_id'] ?? null) !== 1
            || ($sharedCharger['outlets'][1]['connector_id'] ?? null) !== 2)->toBeFalse('Shared outlets should resolve to connector IDs 1 and 2 on EVSE 1.');

        $offlineEventDatabase = new PDO('sqlite:' . $databasePath);
        $offlineEvent = (new TransactionEventOutboxRepository($offlineEventDatabase))->enqueue($chargerId, [
            'eventType' => 'Started',
            'timestamp' => gmdate(DATE_ATOM),
            'triggerReason' => 'CablePluggedIn',
            'seqNo' => 0,
            'transactionInfo' => ['transactionId' => 'offline-restart-integration', 'chargingState' => 'EVConnected'],
            'evse' => ['id' => 1, 'connectorId' => 1],
        ]);
        unset($offlineEventDatabase);
        stopProcess($worker);
        $worker = startProcess([PHP_BINARY, __DIR__ . '/../bin/station-worker.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        $reconnectedOutput = waitForProcessOutput(
            $mockCsms,
            'Connected GET /ocpp/UiIntegrationCharger HTTP/1.1',
            4_000,
        );
        expect($reconnectedOutput)->toContain('Connected GET /ocpp/UiIntegrationCharger HTTP/1.1');
        $offlineEventOutput = ($GLOBALS['capturedMockCsmsOutput'] ?? '')
            . waitForProcessOutput($mockCsms, '"offline":true', 3_000);
        expect($offlineEventOutput)->toContain('"offline":true')
            ->and($offlineEventOutput)->toContain('offline-restart-integration');
        $offlineDeliveryDatabase = new PDO('sqlite:' . $databasePath);
        $offlineDelivery = $offlineDeliveryDatabase->query(
            "SELECT status, payload FROM transaction_event_outbox WHERE id = {$offlineEvent['id']}",
        )->fetch(PDO::FETCH_ASSOC);
        expect($offlineDelivery['status'] ?? null)->toBe('delivered')
            ->and(json_decode($offlineDelivery['payload'] ?? '{}', true)['offline'] ?? null)->toBeTrue();
        unset($offlineDeliveryDatabase);

        $mockCsmsOutput = $GLOBALS['capturedMockCsmsOutput'] ?? '';
        foreach ($GLOBALS['testProcesses'] as [$process, $pipes]) {
            if ($process === $mockCsms) {
                $mockCsmsOutput .= stream_get_contents($pipes[1]);
                break;
            }
        }
        $stationConnectionCount = substr_count($mockCsmsOutput, 'GET /ocpp/UiIntegrationCharger HTTP/1.1');
        expect($stationConnectionCount)->toBeGreaterThanOrEqual(2, 'The charger should establish a new OCPP-J session after the worker restart.');
        expect(!str_contains($mockCsmsOutput, 'GET /ocpp/DemoCharger%202 HTTP/1.1'))->toBeFalse('The mock CSMS should accept simultaneous station sessions with distinct identities.');

        $database = new PDO('sqlite:' . $databasePath, options: [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $charger = $database->query('SELECT * FROM charger WHERE id = ' . $chargerId)->fetch();
        $bootCount = (int) $database->query("SELECT COUNT(*) FROM exchange_log WHERE charger_id = {$chargerId} AND action = 'BootNotification'")->fetchColumn();
        $authorizeCount = (int) $database->query("SELECT COUNT(*) FROM exchange_log WHERE charger_id = {$chargerId} AND action = 'Authorize'")->fetchColumn();
        $statusNotificationCount = (int) $database->query("SELECT COUNT(*) FROM exchange_log WHERE charger_id = {$chargerId} AND action = 'StatusNotification'")->fetchColumn();
        $transactionEventCount = (int) $database->query("SELECT COUNT(*) FROM exchange_log WHERE charger_id = {$chargerId} AND action = 'TransactionEvent'")->fetchColumn();
        $periodicTransactionEventCount = (int) $database->query("SELECT COUNT(*) FROM exchange_log WHERE charger_id = {$chargerId} AND action = 'TransactionEvent' AND message LIKE '%MeterValuePeriodic%'")->fetchColumn();
        $heartbeatCount = (int) $database->query("SELECT COUNT(*) FROM exchange_log WHERE charger_id = {$chargerId} AND action = 'Heartbeat'")->fetchColumn();
        $serverCallCount = (int) $database->query("SELECT COUNT(*) FROM exchange_log WHERE charger_id = {$chargerId} AND action = 'GetVariables'")->fetchColumn();
        expect($charger['boot_status'] !== 'Accepted'
            || (int) $charger['outlet_count'] !== 2
            || $charger['outlet_topology'] !== 'shared'
            || $bootCount !== 4
            || $authorizeCount !== 2
            || $statusNotificationCount !== 12
            || $transactionEventCount !== 22 + (2 * $periodicTransactionEventCount)
            || $heartbeatCount < 2
            || $serverCallCount !== 4)->toBeFalse('The accepted status, topology, and both OCPP exchanges should persist in SQLite: ' . json_encode([
                'boot' => $bootCount,
                'authorize' => $authorizeCount,
                'status' => $statusNotificationCount,
                'transaction' => $transactionEventCount,
                'periodic_transaction' => $periodicTransactionEventCount,
                'heartbeat' => $heartbeatCount,
                'server' => $serverCallCount,
            ]));

        [$deleteBody, $deleteHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/charger/delete",
            'POST',
            json_encode(['charger_id' => $chargerId], JSON_THROW_ON_ERROR),
            'application/json',
        );
        [$remainingStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $remainingState = is_string($remainingStateBody) ? json_decode($remainingStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        foreach ($GLOBALS['testProcesses'] as [$process, $pipes]) {
            if ($process === $mockCsms) {
                $mockCsmsOutput .= stream_get_contents($pipes[1]);
                break;
            }
        }
        expect(!str_contains($deleteHeaders[0] ?? '', '200')
            || count($remainingState['chargers'] ?? []) !== 1
            || ($remainingState['chargers'][0]['id'] ?? null) !== $secondChargerId
            || !str_contains($mockCsmsOutput, 'Disconnected GET /ocpp/UiIntegrationCharger HTTP/1.1'))->toBeFalse('Removing a charger should close its persistent session and preserve the other station.');

    } finally {
        if (is_resource($app)) {
            stopProcess($app);
        }
        if (is_resource($worker)) {
            stopProcess($worker);
        }
        stopProcess($mockCsms);
        foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $directory . '/mock-csms-boot-count', $directory . '/remote-start-sent', $directory . '/remote-start-continue'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
})->group('integration');

it('preserves a waiting remote-start deadline across worker restart without reconnecting early or duplicating events', function (): void {
    $GLOBALS['capturedMockCsmsOutput'] = '';
    $GLOBALS['testProcesses'] = [];
    $directory = sys_get_temp_dir() . '/charger-remote-start-restart-' . bin2hex(random_bytes(6));
    mkdir($directory, 0775, true);

    $databasePath = $directory . '/emulator.sqlite';
    $mockCsmsPort = findOpenPort();
    $appPort = findOpenPort();
    $mockCsms = startProcess([PHP_BINARY, __DIR__ . '/../bin/mock-csms.php'], [
        'PATH' => (string) getenv('PATH'),
        'MOCK_CSMS_PORT' => (string) $mockCsmsPort,
        'MOCK_CSMS_HEARTBEAT_INTERVAL' => '30',
        'MOCK_CSMS_SEND_CALL' => '1',
        'MOCK_CSMS_SEND_REMOTE_START' => '1',
        'MOCK_CSMS_REMOTE_START_SENT_PATH' => $directory . '/remote-start-sent',
        'MOCK_CSMS_REMOTE_START_CONTINUE_PATH' => $directory . '/remote-start-continue',
        'MOCK_CSMS_SET_METER_SETTINGS' => '1',
    ]);
    $worker = null;
    $app = null;

    try {
        waitUntilAvailable($mockCsmsPort);
        $worker = startProcess([PHP_BINARY, __DIR__ . '/../bin/station-worker.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilDatabaseReady($databasePath);
        $app = startProcess([PHP_BINARY, '-S', "127.0.0.1:{$appPort}", '-t', __DIR__ . '/../public', __DIR__ . '/../public/router.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilAvailable($appPort);

        [$stateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $state = is_string($stateBody) ? json_decode($stateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $chargerId = (int) ($state['chargers'][0]['id'] ?? 0);
        $chargerPayload = json_encode([
            'charger_id' => $chargerId,
            'uses_default_csms' => false,
            'station_identity' => 'RestartPendingCharger',
            'csms_url' => "ws://127.0.0.1:{$mockCsmsPort}/ocpp",
            'vendor_name' => 'Test Vendor',
            'model' => 'Test Model',
            'serial_number' => 'RESTART-001',
            'outlet_count' => 1,
            'outlet_topology' => 'independent',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        [, $saveHeaders] = request("http://127.0.0.1:{$appPort}/api/charger", 'POST', $chargerPayload, 'application/json');
        expect(!str_contains($saveHeaders[0] ?? '', '200'))->toBeFalse('The charger configuration should save before booting.');

        [$bootBody, $bootHeaders] = request("http://127.0.0.1:{$appPort}/api/boot", 'POST', $chargerPayload, 'application/json');
        expect(!str_contains($bootHeaders[0] ?? '', '200') || !is_string($bootBody))->toBeFalse('The CSMS should accept the charger boot.');

        $remoteStartOutput = (string) ($GLOBALS['capturedMockCsmsOutput'] ?? '');
        if (!str_contains($remoteStartOutput, 'RemoteStart TransactionEvent: ')) {
            $remoteStartOutput .= waitForProcessOutput($mockCsms, 'RemoteStart TransactionEvent: ', 5_000);
        }
        expect($remoteStartOutput)->toContain('RemoteStart station response: ')
            ->and($remoteStartOutput)->toContain('RemoteStart TransactionEvent: ');

        $stateBeforeRestartBody = '';
        $stateBeforeRestart = [];
        $stateDeadline = microtime(true) + 3;
        do {
            [$stateBeforeRestartBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
            $stateBeforeRestart = is_string($stateBeforeRestartBody)
                ? json_decode($stateBeforeRestartBody, true, flags: JSON_THROW_ON_ERROR)
                : [];
            $outletBeforeRestart = $stateBeforeRestart['chargers'][0]['outlets'][0] ?? [];
            if (($outletBeforeRestart['remote_start_pending'] ?? false) === true) {
                break;
            }

            usleep(20_000);
        } while (microtime(true) < $stateDeadline);
        $outletBeforeRestart = $stateBeforeRestart['chargers'][0]['outlets'][0] ?? [];
        $database = new PDO('sqlite:' . $databasePath);
        $deadlineBeforeRestart = $database->query(
            "SELECT remote_start_deadline FROM outlet_session WHERE charger_id = {$chargerId} AND evse_id = 1 AND connector_id = 1",
        )->fetchColumn();
        unset($database);
        expect($outletBeforeRestart['remote_start_pending'] ?? null)->toBeTrue()
            ->and($deadlineBeforeRestart)->toBeString();

        drainProcessOutput($mockCsms);
        stopProcess($worker);
        drainProcessOutput($mockCsms);
        $worker = startProcess([PHP_BINARY, __DIR__ . '/../bin/station-worker.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilDatabaseReady($databasePath);

        $earlyReconnectOutput = waitForProcessOutput($mockCsms, 'No early reconnect expected', 250);
        expect($earlyReconnectOutput)->not->toContain('Connected GET /ocpp/RestartPendingCharger HTTP/1.1');
        [$earlyStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $earlyState = is_string($earlyStateBody) ? json_decode($earlyStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $earlyOutlet = $earlyState['chargers'][0]['outlets'][0] ?? [];
        expect($earlyOutlet['remote_start_pending'] ?? null)->toBeTrue();

        $reconnectOutput = waitForProcessOutput($mockCsms, 'Connected GET /ocpp/RestartPendingCharger HTTP/1.1', 3_000);
        expect($reconnectOutput)->toContain('Connected GET /ocpp/RestartPendingCharger HTTP/1.1');
        usleep(250_000);
        [$stateAfterRestartBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $stateAfterRestart = is_string($stateAfterRestartBody)
            ? json_decode($stateAfterRestartBody, true, flags: JSON_THROW_ON_ERROR)
            : [];
        $chargerAfterRestart = $stateAfterRestart['chargers'][0] ?? [];
        $outletAfterRestart = $chargerAfterRestart['outlets'][0] ?? [];
        $transactionEvents = array_values(array_filter(
            $chargerAfterRestart['entries'] ?? [],
            static fn (array $entry): bool => ($entry['action'] ?? null) === 'TransactionEvent'
                && ($entry['direction'] ?? null) === 'outgoing',
        ));
        $startedEvents = array_values(array_filter($transactionEvents, static function (array $entry): bool {
            $call = json_decode($entry['message'], true);

            return ($call[3]['eventType'] ?? null) === 'Started'
                && ($call[3]['triggerReason'] ?? null) === 'RemoteStart';
        }));
        $endedEvents = array_values(array_filter($transactionEvents, static function (array $entry): bool {
            $call = json_decode($entry['message'], true);

            return ($call[3]['eventType'] ?? null) === 'Ended';
        }));
        $database = new PDO('sqlite:' . $databasePath);
        $deadlineAfterRestart = $database->query(
            "SELECT remote_start_deadline FROM outlet_session WHERE charger_id = {$chargerId} AND evse_id = 1 AND connector_id = 1",
        )->fetchColumn();
        $bootCount = (int) $database->query(
            "SELECT COUNT(*) FROM exchange_log WHERE charger_id = {$chargerId} AND action = 'BootNotification' AND direction = 'outgoing'",
        )->fetchColumn();
        unset($database);

        expect($outletAfterRestart['remote_start_pending'] ?? null)->toBeTrue()
            ->and($deadlineAfterRestart)->toBe($deadlineBeforeRestart)
            ->and($transactionEvents)->toHaveCount(1, 'Restarting the worker must not duplicate any transaction event.')
            ->and($startedEvents)->toHaveCount(1)
            ->and($endedEvents)->toBeEmpty()
            ->and($bootCount)->toBe(1, 'A worker restart after Accepted BootNotification must reconnect without sending BootNotification again.');
    } finally {
        if (is_resource($app)) {
            stopProcess($app);
        }
        if (is_resource($worker)) {
            stopProcess($worker);
        }
        stopProcess($mockCsms);
        foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $directory . '/remote-start-sent', $directory . '/remote-start-continue'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
})->group('integration');

it('ends a remote-start transaction when the CSMS stops it before vehicle connection', function (): void {
    $GLOBALS['capturedMockCsmsOutput'] = '';
    $GLOBALS['testProcesses'] = [];
    $directory = sys_get_temp_dir() . '/charger-remote-stop-' . bin2hex(random_bytes(6));
    mkdir($directory, 0775, true);

    $databasePath = $directory . '/emulator.sqlite';
    $mockCsmsPort = findOpenPort();
    $appPort = findOpenPort();
    $mockCsms = startProcess([PHP_BINARY, __DIR__ . '/../bin/mock-csms.php'], [
        'PATH' => (string) getenv('PATH'),
        'MOCK_CSMS_PORT' => (string) $mockCsmsPort,
        'MOCK_CSMS_HEARTBEAT_INTERVAL' => '30',
        'MOCK_CSMS_SEND_CALL' => '1',
        'MOCK_CSMS_SEND_REMOTE_START' => '1',
        'MOCK_CSMS_REMOTE_STOP_BEFORE_PLUG' => '1',
        'MOCK_CSMS_SEND_CHANGE_AFTER_REMOTE_START' => '1',
        'MOCK_CSMS_REMOTE_START_SENT_PATH' => $directory . '/remote-start-sent',
        'MOCK_CSMS_REMOTE_START_CONTINUE_PATH' => $directory . '/remote-start-continue',
    ]);
    $worker = null;
    $app = null;

    try {
        waitUntilAvailable($mockCsmsPort);
        $worker = startProcess([PHP_BINARY, __DIR__ . '/../bin/station-worker.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilDatabaseReady($databasePath);
        $app = startProcess([PHP_BINARY, '-S', "127.0.0.1:{$appPort}", '-t', __DIR__ . '/../public', __DIR__ . '/../public/router.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilAvailable($appPort);

        [$stateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $state = is_string($stateBody) ? json_decode($stateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $chargerId = (int) ($state['chargers'][0]['id'] ?? 0);
        $chargerPayload = json_encode([
            'charger_id' => $chargerId,
            'uses_default_csms' => false,
            'station_identity' => 'RemoteStopPendingCharger',
            'csms_url' => "ws://127.0.0.1:{$mockCsmsPort}/ocpp",
            'vendor_name' => 'Test Vendor',
            'model' => 'Test Model',
            'serial_number' => 'REMOTE-STOP-001',
            'outlet_count' => 1,
            'outlet_topology' => 'independent',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        [, $saveHeaders] = request("http://127.0.0.1:{$appPort}/api/charger", 'POST', $chargerPayload, 'application/json');
        expect(!str_contains($saveHeaders[0] ?? '', '200'))->toBeFalse('The test charger should save before booting.');

        [$bootBody, $bootHeaders] = request("http://127.0.0.1:{$appPort}/api/boot", 'POST', $chargerPayload, 'application/json');
        expect(!str_contains($bootHeaders[0] ?? '', '200') || !is_string($bootBody))->toBeFalse('The CSMS should accept the test charger boot.');

        $remoteStopOutput = (string) ($GLOBALS['capturedMockCsmsOutput'] ?? '');
        if (!str_contains($remoteStopOutput, 'RemoteStop station response: ')) {
            $remoteStopOutput .= waitForProcessOutput($mockCsms, 'RemoteStop station response: ', 5_000);
        }
        if (!str_contains($remoteStopOutput, 'RemoteStop TransactionEvent: ')) {
            $remoteStopOutput .= waitForProcessOutput($mockCsms, 'RemoteStop TransactionEvent: ', 5_000);
        }

        expect($remoteStopOutput)->toContain('RemoteStart TransactionEvent: ')
            ->and($remoteStopOutput)->toContain('RemoteStop station response: ')
            ->and($remoteStopOutput)->toContain('"status":"Accepted"')
            ->and($remoteStopOutput)->toContain('ChangeAvailability station response: ')
            ->and($remoteStopOutput)->toContain('"status":"Scheduled"')
            ->and($remoteStopOutput)->toContain('RemoteStop availability status: ')
            ->and($remoteStopOutput)->toContain('RemoteStop TransactionEvent: ');
        expect(strpos($remoteStopOutput, 'RemoteStop TransactionEvent: '))
            ->toBeLessThan(strpos($remoteStopOutput, 'RemoteStop availability status: '));

        $stoppedCharger = [];
        $outlet = [];
        $endedEvents = [];
        $stateDeadline = microtime(true) + 5;
        do {
            [$stoppedStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
            $stoppedState = is_string($stoppedStateBody) ? json_decode($stoppedStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
            $stoppedCharger = $stoppedState['chargers'][0] ?? [];
            $outlet = $stoppedCharger['outlets'][0] ?? [];
            $endedEvents = array_values(array_filter(
                $stoppedCharger['entries'] ?? [],
                static function (array $entry): bool {
                    if (($entry['action'] ?? null) !== 'TransactionEvent' || ($entry['direction'] ?? null) !== 'outgoing') {
                        return false;
                    }

                    $call = json_decode($entry['message'], true);

                    return ($call[3]['eventType'] ?? null) === 'Ended'
                        && ($call[3]['triggerReason'] ?? null) === 'RemoteStop';
                },
            ));
            if ($endedEvents !== []) {
                break;
            }

            usleep(20_000);
        } while (microtime(true) < $stateDeadline);
        $endedEvent = json_decode($endedEvents[0]['message'] ?? 'null', true);
        $startedRemoteEvents = array_values(array_filter(
            $stoppedCharger['entries'] ?? [],
            static function (array $entry): bool {
                if (($entry['action'] ?? null) !== 'TransactionEvent' || ($entry['direction'] ?? null) !== 'outgoing') {
                    return false;
                }

                $call = json_decode($entry['message'], true);

                return ($call[3]['eventType'] ?? null) === 'Started'
                    && ($call[3]['triggerReason'] ?? null) === 'RemoteStart';
            },
        ));
        $startedRemoteEvent = json_decode($startedRemoteEvents[0]['message'] ?? 'null', true);
        expect(($outlet['connector_status'] ?? null))->toBe('Unavailable')
            ->and($outlet['remote_start_pending'] ?? null)->toBeFalse()
            ->and($startedRemoteEvents)->toHaveCount(1)
            ->and($startedRemoteEvent[3]['meterValue'][0]['sampledValue'][0]['context'] ?? null)->toBe('Transaction.Begin')
            ->and($startedRemoteEvent[3]['meterValue'][0]['sampledValue'][0]['measurand'] ?? null)->toBe('Energy.Active.Import.Register')
            ->and($endedEvents)->toHaveCount(1, 'Expected one RemoteStop Ended event; observed: ' . json_encode(array_map(
                static fn (array $entry): mixed => json_decode($entry['message'], true),
                array_values(array_filter(
                    $stoppedCharger['entries'] ?? [],
                    static fn (array $entry): bool => ($entry['action'] ?? null) === 'TransactionEvent',
                )),
            )))
            ->and($endedEvent[3]['transactionInfo']['stoppedReason'] ?? null)->toBe('Remote')
            ->and($endedEvent[3]['meterValue'][0]['sampledValue'][0]['context'] ?? null)->toBe('Transaction.End')
            ->and(isset($endedEvent[3]['transactionInfo']['chargingState']))->toBeFalse();

        [$refreshedStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $refreshedState = is_string($refreshedStateBody) ? json_decode($refreshedStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        expect($refreshedState['chargers'][0]['outlets'][0]['remote_start_pending'] ?? null)->toBeFalse('Refreshing state should not restore a stopped pending remote-start transaction.');
    } finally {
        if (is_resource($app)) {
            stopProcess($app);
        }
        if (is_resource($worker)) {
            stopProcess($worker);
        }
        stopProcess($mockCsms);
        foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $directory . '/remote-start-sent', $directory . '/remote-start-continue'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
})->group('integration');

it('applies CSMS ChangeAvailability and reports the connector status', function (): void {
    $GLOBALS['capturedMockCsmsOutput'] = '';
    $GLOBALS['testProcesses'] = [];
    $directory = sys_get_temp_dir() . '/charger-change-availability-' . bin2hex(random_bytes(6));
    mkdir($directory, 0775, true);
    $databasePath = $directory . '/emulator.sqlite';
    $mockCsmsPort = findOpenPort();
    $appPort = findOpenPort();
    $mockCsms = startProcess([PHP_BINARY, __DIR__ . '/../bin/mock-csms.php'], [
        'PATH' => (string) getenv('PATH'),
        'MOCK_CSMS_PORT' => (string) $mockCsmsPort,
        'MOCK_CSMS_HEARTBEAT_INTERVAL' => '30',
        'MOCK_CSMS_SEND_CHANGE_AVAILABILITY' => '1',
    ]);
    $worker = null;
    $app = null;

    try {
        waitUntilAvailable($mockCsmsPort);
        $worker = startProcess([PHP_BINARY, __DIR__ . '/../bin/station-worker.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilDatabaseReady($databasePath);
        $app = startProcess([PHP_BINARY, '-S', "127.0.0.1:{$appPort}", '-t', __DIR__ . '/../public', __DIR__ . '/../public/router.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilAvailable($appPort);

        [$stateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $state = is_string($stateBody) ? json_decode($stateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $chargerId = (int) ($state['chargers'][0]['id'] ?? 0);
        $chargerPayload = json_encode([
            'charger_id' => $chargerId,
            'uses_default_csms' => false,
            'station_identity' => 'AvailabilityIntegrationCharger',
            'csms_url' => "ws://127.0.0.1:{$mockCsmsPort}/ocpp",
            'vendor_name' => 'Test Vendor',
            'model' => 'Availability Test Model',
            'serial_number' => 'AVAIL-001',
            'outlet_count' => 1,
            'outlet_topology' => 'independent',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        [, $saveHeaders] = request("http://127.0.0.1:{$appPort}/api/charger", 'POST', $chargerPayload, 'application/json');
        expect(!str_contains($saveHeaders[0] ?? '', '200'))->toBeFalse('The test charger should save before booting.');

        [$bootBody, $bootHeaders] = request("http://127.0.0.1:{$appPort}/api/boot", 'POST', $chargerPayload, 'application/json');
        $bootCsmsOutput = drainProcessOutput($mockCsms);
        expect(!str_contains($bootHeaders[0] ?? '', '200'))->toBeFalse('The test charger should complete BootNotification: ' . (string) $bootBody . ' CSMS: ' . $bootCsmsOutput);
        $csmsOutput = (string) ($GLOBALS['capturedMockCsmsOutput'] ?? '');
        if (!str_contains($csmsOutput, 'ChangeAvailability status notification: ')) {
            $csmsOutput .= waitForProcessOutput($mockCsms, 'ChangeAvailability status notification: ', 3_000);
        }

        expect($csmsOutput)->toContain('ChangeAvailability station response: ')
            ->toContain('"status":"Accepted"')
            ->toContain('"connectorStatus":"Unavailable"');

        [$updatedStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $updatedState = is_string($updatedStateBody) ? json_decode($updatedStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        expect($updatedState['chargers'][0]['outlets'][0]['connector_status'] ?? null)->toBe('Unavailable');

        [, $plugHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/outlet/plug-in",
            'POST',
            json_encode(['charger_id' => $chargerId, 'outlet_number' => 1], JSON_THROW_ON_ERROR),
            'application/json',
        );
        expect(!str_contains($plugHeaders[0] ?? '', '409'))->toBeFalse('An inoperative outlet must reject a simulated vehicle connection.');
    } finally {
        if ($app !== null) {
            stopProcess($app);
        }
        if ($worker !== null) {
            stopProcess($worker);
        }
        stopProcess($mockCsms);
        foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
})->group('integration');

it('answers TriggerMessage for every connector and sends each current StatusNotification', function (): void {
    $GLOBALS['capturedMockCsmsOutput'] = '';
    $GLOBALS['testProcesses'] = [];
    $directory = sys_get_temp_dir() . '/charger-trigger-message-' . bin2hex(random_bytes(6));
    mkdir($directory, 0775, true);
    $databasePath = $directory . '/emulator.sqlite';
    $mockCsmsPort = findOpenPort();
    $appPort = findOpenPort();
    $mockCsms = startProcess([PHP_BINARY, __DIR__ . '/../bin/mock-csms.php'], [
        'PATH' => (string) getenv('PATH'),
        'MOCK_CSMS_PORT' => (string) $mockCsmsPort,
        'MOCK_CSMS_HEARTBEAT_INTERVAL' => '30',
        'MOCK_CSMS_SEND_TRIGGER_MESSAGE' => '1',
        'MOCK_CSMS_TRIGGER_EXPECTED_STATUSES' => '2',
    ]);
    $worker = null;
    $app = null;

    try {
        waitUntilAvailable($mockCsmsPort);
        $worker = startProcess([PHP_BINARY, __DIR__ . '/../bin/station-worker.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilDatabaseReady($databasePath);
        $app = startProcess([PHP_BINARY, '-S', "127.0.0.1:{$appPort}", '-t', __DIR__ . '/../public', __DIR__ . '/../public/router.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilAvailable($appPort);

        [$stateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $state = is_string($stateBody) ? json_decode($stateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $chargerId = (int) ($state['chargers'][0]['id'] ?? 0);
        $chargerPayload = json_encode([
            'charger_id' => $chargerId,
            'uses_default_csms' => false,
            'station_identity' => 'TriggerMessageCharger',
            'csms_url' => "ws://127.0.0.1:{$mockCsmsPort}/ocpp",
            'vendor_name' => 'Test Vendor',
            'model' => 'Trigger Message Test Model',
            'serial_number' => 'TRIGGER-001',
            'outlet_count' => 2,
            'outlet_topology' => 'independent',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        [, $saveHeaders] = request("http://127.0.0.1:{$appPort}/api/charger", 'POST', $chargerPayload, 'application/json');
        expect(!str_contains($saveHeaders[0] ?? '', '200'))->toBeFalse('The test charger should save before booting.');

        [$bootBody, $bootHeaders] = request("http://127.0.0.1:{$appPort}/api/boot", 'POST', $chargerPayload, 'application/json');
        $bootCsmsOutput = drainProcessOutput($mockCsms);
        expect(!str_contains($bootHeaders[0] ?? '', '200'))->toBeFalse('The test charger should complete BootNotification: ' . (string) $bootBody . ' CSMS: ' . $bootCsmsOutput);
        $csmsOutput = (string) ($GLOBALS['capturedMockCsmsOutput'] ?? '');
        if (!str_contains($csmsOutput, '"evseId":2,"connectorId":1')) {
            waitForProcessOutput($mockCsms, '"evseId":2,"connectorId":1', 3_000);
            usleep(50_000);
            $csmsOutput = (string) ($GLOBALS['capturedMockCsmsOutput'] ?? '') . drainProcessOutput($mockCsms);
        }
        expect($csmsOutput)->toContain('TriggerMessage CSMS call: [2,"mock-csms-trigger-message-')
            ->toContain('TriggerMessage station response: [3,"mock-csms-trigger-message-')
            ->toContain('"status":"Accepted"')
            ->toContain('"requestedMessage":"StatusNotification"')
            ->toContain('"evseId":1,"connectorId":1')
            ->toContain('"evseId":2,"connectorId":1');

        $triggerResponsePosition = strpos($csmsOutput, 'TriggerMessage station response:');
        $firstTriggeredStatusPosition = strpos($csmsOutput, 'TriggerMessage status notification:');
        expect($triggerResponsePosition)->toBeInt()
            ->and($firstTriggeredStatusPosition)->toBeInt()
            ->and($triggerResponsePosition)->toBeLessThan($firstTriggeredStatusPosition, 'The TriggerMessage response must be sent before the requested StatusNotification calls.');
    } finally {
        if ($app !== null) {
            stopProcess($app);
        }
        if ($worker !== null) {
            stopProcess($worker);
        }
        stopProcess($mockCsms);
        foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
})->group('integration');

it('sends Heartbeat after accepting a TriggerMessage and ignores its irrelevant EVSE', function (): void {
    $GLOBALS['capturedMockCsmsOutput'] = '';
    $GLOBALS['testProcesses'] = [];
    $directory = sys_get_temp_dir() . '/charger-trigger-heartbeat-' . bin2hex(random_bytes(6));
    mkdir($directory, 0775, true);
    $databasePath = $directory . '/emulator.sqlite';
    $mockCsmsPort = findOpenPort();
    $appPort = findOpenPort();
    $mockCsms = startProcess([PHP_BINARY, __DIR__ . '/../bin/mock-csms.php'], [
        'PATH' => (string) getenv('PATH'),
        'MOCK_CSMS_PORT' => (string) $mockCsmsPort,
        'MOCK_CSMS_HEARTBEAT_INTERVAL' => '30',
        'MOCK_CSMS_SEND_TRIGGER_MESSAGE' => '1',
        'MOCK_CSMS_TRIGGER_MESSAGE' => 'Heartbeat',
        'MOCK_CSMS_TRIGGER_EXPECTED_STATUSES' => '1',
        'MOCK_CSMS_INITIAL_STATUS_COUNT' => '2',
        'MOCK_CSMS_TRIGGER_INCLUDE_EVSE' => '1',
    ]);
    $worker = null;
    $app = null;

    try {
        waitUntilAvailable($mockCsmsPort);
        $worker = startProcess([PHP_BINARY, __DIR__ . '/../bin/station-worker.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilDatabaseReady($databasePath);
        $app = startProcess([PHP_BINARY, '-S', "127.0.0.1:{$appPort}", '-t', __DIR__ . '/../public', __DIR__ . '/../public/router.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilAvailable($appPort);

        [$stateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $state = is_string($stateBody) ? json_decode($stateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $chargerId = (int) ($state['chargers'][0]['id'] ?? 0);
        $chargerPayload = json_encode([
            'charger_id' => $chargerId,
            'uses_default_csms' => false,
            'station_identity' => 'TriggerHeartbeatCharger',
            'csms_url' => "ws://127.0.0.1:{$mockCsmsPort}/ocpp",
            'vendor_name' => 'Test Vendor',
            'model' => 'Trigger Heartbeat Test Model',
            'serial_number' => 'TRIGGER-HB-001',
            'outlet_count' => 2,
            'outlet_topology' => 'independent',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        [, $saveHeaders] = request("http://127.0.0.1:{$appPort}/api/charger", 'POST', $chargerPayload, 'application/json');
        expect(!str_contains($saveHeaders[0] ?? '', '200'))->toBeFalse('The test charger should save before booting.');

        [$bootBody, $bootHeaders] = request("http://127.0.0.1:{$appPort}/api/boot", 'POST', $chargerPayload, 'application/json');
        $bootCsmsOutput = drainProcessOutput($mockCsms);
        expect(!str_contains($bootHeaders[0] ?? '', '200'))->toBeFalse('The test charger should complete BootNotification: ' . (string) $bootBody . ' CSMS: ' . $bootCsmsOutput);
        $csmsOutput = (string) ($GLOBALS['capturedMockCsmsOutput'] ?? '');
        if (!str_contains($csmsOutput, 'TriggerMessage Heartbeat: ')) {
            $csmsOutput .= waitForProcessOutput($mockCsms, 'TriggerMessage Heartbeat: ', 3_000);
        }

        expect($csmsOutput)->toContain('TriggerMessage CSMS call: [2,"mock-csms-trigger-message-')
            ->toContain('"requestedMessage":"Heartbeat"')
            ->toContain('"evse":{"id":99}')
            ->toContain('TriggerMessage station response: [3,"mock-csms-trigger-message-')
            ->toContain('"status":"Accepted"')
            ->toContain('TriggerMessage Heartbeat: [2,')
            ->toContain(',"Heartbeat",{}]');

        $triggerResponsePosition = strpos($csmsOutput, 'TriggerMessage station response:');
        $heartbeatPosition = strpos($csmsOutput, 'TriggerMessage Heartbeat:');
        expect($triggerResponsePosition)->toBeInt()
            ->and($heartbeatPosition)->toBeInt()
            ->and($triggerResponsePosition)->toBeLessThan($heartbeatPosition, 'The TriggerMessage response must precede the triggered Heartbeat.');
    } finally {
        if ($app !== null) {
            stopProcess($app);
        }
        if ($worker !== null) {
            stopProcess($worker);
        }
        stopProcess($mockCsms);
        foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
})->group('integration');

it('sends current MeterValues for every EVSE after accepting a TriggerMessage', function (): void {
    $GLOBALS['capturedMockCsmsOutput'] = '';
    $GLOBALS['testProcesses'] = [];
    $directory = sys_get_temp_dir() . '/charger-trigger-meter-values-' . bin2hex(random_bytes(6));
    mkdir($directory, 0775, true);
    $databasePath = $directory . '/emulator.sqlite';
    $mockCsmsPort = findOpenPort();
    $appPort = findOpenPort();
    $mockCsms = startProcess([PHP_BINARY, __DIR__ . '/../bin/mock-csms.php'], [
        'PATH' => (string) getenv('PATH'),
        'MOCK_CSMS_PORT' => (string) $mockCsmsPort,
        'MOCK_CSMS_HEARTBEAT_INTERVAL' => '30',
        'MOCK_CSMS_SEND_TRIGGER_MESSAGE' => '1',
        'MOCK_CSMS_TRIGGER_MESSAGE' => 'MeterValues',
        'MOCK_CSMS_TRIGGER_EXPECTED_METER_VALUES' => '2',
        'MOCK_CSMS_TRIGGER_EXPECTED_STATUSES' => '2',
    ]);
    $worker = null;
    $app = null;

    try {
        waitUntilAvailable($mockCsmsPort);
        $worker = startProcess([PHP_BINARY, __DIR__ . '/../bin/station-worker.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilDatabaseReady($databasePath);
        $app = startProcess([PHP_BINARY, '-S', "127.0.0.1:{$appPort}", '-t', __DIR__ . '/../public', __DIR__ . '/../public/router.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilAvailable($appPort);

        [$stateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $state = is_string($stateBody) ? json_decode($stateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $chargerId = (int) ($state['chargers'][0]['id'] ?? 0);
        $chargerPayload = json_encode([
            'charger_id' => $chargerId,
            'uses_default_csms' => false,
            'station_identity' => 'TriggerMeterValuesCharger',
            'csms_url' => "ws://127.0.0.1:{$mockCsmsPort}/ocpp",
            'vendor_name' => 'Test Vendor',
            'model' => 'Trigger Meter Values Test Model',
            'serial_number' => 'TRIGGER-MV-001',
            'outlet_count' => 2,
            'outlet_topology' => 'independent',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        [, $saveHeaders] = request("http://127.0.0.1:{$appPort}/api/charger", 'POST', $chargerPayload, 'application/json');
        expect(!str_contains($saveHeaders[0] ?? '', '200'))->toBeFalse('The test charger should save before booting.');

        [$bootBody, $bootHeaders] = request("http://127.0.0.1:{$appPort}/api/boot", 'POST', $chargerPayload, 'application/json');
        $bootCsmsOutput = drainProcessOutput($mockCsms);
        expect(!str_contains($bootHeaders[0] ?? '', '200'))->toBeFalse('The test charger should complete BootNotification: ' . (string) $bootBody . ' CSMS: ' . $bootCsmsOutput);
        $csmsOutput = (string) ($GLOBALS['capturedMockCsmsOutput'] ?? '');
        if (!str_contains($csmsOutput, ',"MeterValues",{"evseId":2,"meterValue":')) {
            $csmsOutput .= waitForProcessOutput($mockCsms, ',"MeterValues",{"evseId":2,"meterValue":', 3_000);
        }

        expect($csmsOutput)->toContain('"requestedMessage":"MeterValues"')
            ->toContain('TriggerMessage station response: [3,"mock-csms-trigger-message-')
            ->toContain('"status":"Accepted"')
            ->toContain('TriggerMessage MeterValues: [2,')
            ->toContain(',"MeterValues",{"evseId":1,"meterValue":[{"timestamp":')
            ->toContain('"context":"Trigger","measurand":"Energy.Active.Import.Register"')
            ->toContain(',"MeterValues",{"evseId":2,"meterValue":[{"timestamp":');

        $triggerResponsePosition = strpos($csmsOutput, 'TriggerMessage station response:');
        $firstMeterValuesPosition = strpos($csmsOutput, 'TriggerMessage MeterValues:');
        expect($triggerResponsePosition)->toBeInt()
            ->and($firstMeterValuesPosition)->toBeInt()
            ->and($triggerResponsePosition)->toBeLessThan($firstMeterValuesPosition, 'The TriggerMessage response must precede requested MeterValues calls.');
    } finally {
        if ($app !== null) {
            stopProcess($app);
        }
        if ($worker !== null) {
            stopProcess($worker);
        }
        stopProcess($mockCsms);
        foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
})->group('integration');

it('sends clock-aligned MeterValues at the configured UTC interval boundaries', function (): void {
    $GLOBALS['capturedMockCsmsOutput'] = '';
    $GLOBALS['testProcesses'] = [];
    $directory = sys_get_temp_dir() . '/charger-clock-aligned-meter-values-' . bin2hex(random_bytes(6));
    mkdir($directory, 0775, true);
    $databasePath = $directory . '/emulator.sqlite';
    $mockCsmsPort = findOpenPort();
    $appPort = findOpenPort();
    $mockCsms = startProcess([PHP_BINARY, __DIR__ . '/../bin/mock-csms.php'], [
        'PATH' => (string) getenv('PATH'),
        'MOCK_CSMS_PORT' => (string) $mockCsmsPort,
        'MOCK_CSMS_HEARTBEAT_INTERVAL' => '30',
    ]);
    $worker = null;
    $app = null;

    try {
        waitUntilAvailable($mockCsmsPort);
        $worker = startProcess([PHP_BINARY, __DIR__ . '/../bin/station-worker.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilDatabaseReady($databasePath);
        $database = new PDO('sqlite:' . $databasePath);
        $chargerId = (int) $database->query('SELECT id FROM charger ORDER BY id LIMIT 1')->fetchColumn();
        $setting = $database->prepare('INSERT INTO device_model_setting (charger_id, variable, value) VALUES (?, ?, ?)');
        $setting->execute([$chargerId, 'Interval', '1']);
        $setting->execute([$chargerId, 'Measurands', 'Energy.Active.Import.Register']);
        unset($database, $setting);

        $app = startProcess([PHP_BINARY, '-S', "127.0.0.1:{$appPort}", '-t', __DIR__ . '/../public', __DIR__ . '/../public/router.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilAvailable($appPort);

        [$stateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $state = is_string($stateBody) ? json_decode($stateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $chargerId = (int) ($state['chargers'][0]['id'] ?? 0);
        $chargerPayload = json_encode([
            'charger_id' => $chargerId,
            'uses_default_csms' => false,
            'station_identity' => 'ClockAlignedMeterValuesCharger',
            'csms_url' => "ws://127.0.0.1:{$mockCsmsPort}/ocpp",
            'vendor_name' => 'Test Vendor',
            'model' => 'Clock Aligned Meter Values Test Model',
            'serial_number' => 'CLOCK-MV-001',
            'outlet_count' => 2,
            'outlet_topology' => 'independent',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        [, $saveHeaders] = request("http://127.0.0.1:{$appPort}/api/charger", 'POST', $chargerPayload, 'application/json');
        expect(!str_contains($saveHeaders[0] ?? '', '200'))->toBeFalse('The test charger should save before booting.');

        [$bootBody, $bootHeaders] = request("http://127.0.0.1:{$appPort}/api/boot", 'POST', $chargerPayload, 'application/json');
        expect(!str_contains($bootHeaders[0] ?? '', '200'))->toBeFalse('The test charger should complete BootNotification: ' . (string) $bootBody);
        waitForProcessOutput($mockCsms, 'MeterValues response: ', 4_000);
        usleep(50_000);
        $csmsOutput = (string) ($GLOBALS['capturedMockCsmsOutput'] ?? '') . drainProcessOutput($mockCsms);
        preg_match('/OCPP in: (\[2,"[^"]+","MeterValues",[^\n]+\])/', $csmsOutput, $meterValueMatch);
        $meterValueCall = isset($meterValueMatch[1]) ? json_decode($meterValueMatch[1], true, flags: JSON_THROW_ON_ERROR) : null;
        $meterValuePayload = is_array($meterValueCall) && is_array($meterValueCall[3] ?? null) ? $meterValueCall[3] : [];
        $meterValue = $meterValuePayload['meterValue'][0] ?? [];
        $sampledValue = $meterValue['sampledValue'][0] ?? [];
        $sampleTimestamp = is_string($meterValue['timestamp'] ?? null) ? strtotime($meterValue['timestamp']) : false;

        expect($csmsOutput)->toContain('MeterValues response: [3,')
            ->and($meterValueCall[2] ?? null)->toBe('MeterValues')
            ->and($meterValuePayload['evseId'] ?? null)->toBe(1)
            ->and($sampleTimestamp)->toBeInt()
            ->and($sampledValue['context'] ?? null)->toBe('Sample.Clock')
            ->and($sampledValue['measurand'] ?? null)->toBe('Energy.Active.Import.Register')
            ->and($sampledValue['unitOfMeasure']['unit'] ?? null)->toBe('Wh')
            ->and($sampledValue['value'] ?? null)->toBeInt();
    } finally {
        if ($app !== null) {
            stopProcess($app);
        }
        if ($worker !== null) {
            stopProcess($worker);
        }
        stopProcess($mockCsms);
        foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
})->group('integration');

it('sends clock-aligned transaction samples as MeterValueClock events', function (): void {
    $GLOBALS['capturedMockCsmsOutput'] = '';
    $GLOBALS['testProcesses'] = [];
    $directory = sys_get_temp_dir() . '/charger-clock-transaction-meter-values-' . bin2hex(random_bytes(6));
    mkdir($directory, 0775, true);
    $databasePath = $directory . '/emulator.sqlite';
    $mockCsmsPort = findOpenPort();
    $appPort = findOpenPort();
    $mockCsms = startProcess([PHP_BINARY, __DIR__ . '/../bin/mock-csms.php'], [
        'PATH' => (string) getenv('PATH'),
        'MOCK_CSMS_PORT' => (string) $mockCsmsPort,
        'MOCK_CSMS_HEARTBEAT_INTERVAL' => '30',
    ]);
    $worker = null;
    $app = null;

    try {
        waitUntilAvailable($mockCsmsPort);
        $worker = startProcess([PHP_BINARY, __DIR__ . '/../bin/station-worker.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilDatabaseReady($databasePath);
        $database = new PDO('sqlite:' . $databasePath);
        $chargerId = (int) $database->query('SELECT id FROM charger ORDER BY id LIMIT 1')->fetchColumn();
        $setting = $database->prepare('INSERT INTO device_model_setting (charger_id, variable, value) VALUES (?, ?, ?)');
        $setting->execute([$chargerId, 'Interval', '1']);
        unset($database, $setting);

        $app = startProcess([PHP_BINARY, '-S', "127.0.0.1:{$appPort}", '-t', __DIR__ . '/../public', __DIR__ . '/../public/router.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilAvailable($appPort);

        [$stateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $state = is_string($stateBody) ? json_decode($stateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $chargerId = (int) ($state['chargers'][0]['id'] ?? 0);
        $chargerPayload = json_encode([
            'charger_id' => $chargerId,
            'uses_default_csms' => false,
            'station_identity' => 'ClockAlignedTransactionCharger',
            'csms_url' => "ws://127.0.0.1:{$mockCsmsPort}/ocpp",
            'vendor_name' => 'Test Vendor',
            'model' => 'Clock Aligned Transaction Test Model',
            'serial_number' => 'CLOCK-TX-001',
            'outlet_count' => 1,
            'outlet_topology' => 'independent',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        [, $saveHeaders] = request("http://127.0.0.1:{$appPort}/api/charger", 'POST', $chargerPayload, 'application/json');
        expect(!str_contains($saveHeaders[0] ?? '', '200'))->toBeFalse('The test charger should save before booting.');

        [, $bootHeaders] = request("http://127.0.0.1:{$appPort}/api/boot", 'POST', $chargerPayload, 'application/json');
        expect(!str_contains($bootHeaders[0] ?? '', '200'))->toBeFalse('The test charger should boot before starting a transaction.');
        [, $plugHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/outlet/plug-in",
            'POST',
            json_encode(['charger_id' => $chargerId, 'outlet_number' => 1], JSON_THROW_ON_ERROR),
            'application/json',
        );
        expect(!str_contains($plugHeaders[0] ?? '', '200'))->toBeFalse('The vehicle should plug in before authorization.');
        [, $authorizeHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/authorize",
            'POST',
            json_encode(['charger_id' => $chargerId, 'rfid_token' => 'CLOCK-TOKEN', 'outlet_number' => 1], JSON_THROW_ON_ERROR),
            'application/json',
        );
        expect(!str_contains($authorizeHeaders[0] ?? '', '200'))->toBeFalse('The vehicle token should be authorized.');

        waitForProcessOutput($mockCsms, 'MeterValueClock TransactionEvent: ', 5_000);
        usleep(50_000);
        $csmsOutput = (string) ($GLOBALS['capturedMockCsmsOutput'] ?? '') . drainProcessOutput($mockCsms);
        preg_match('/MeterValueClock TransactionEvent: (\[2,"[^"]+","TransactionEvent",[^\n]+\])/', $csmsOutput, $transactionEventMatch);
        $transactionEvent = isset($transactionEventMatch[1])
            ? json_decode($transactionEventMatch[1], true, flags: JSON_THROW_ON_ERROR)
            : null;
        $payload = is_array($transactionEvent) && is_array($transactionEvent[3] ?? null) ? $transactionEvent[3] : [];
        $meterValue = $payload['meterValue'][0] ?? [];
        $sampledValue = $meterValue['sampledValue'][0] ?? [];

        expect($transactionEvent[2] ?? null)->toBe('TransactionEvent')
            ->and($payload['eventType'] ?? null)->toBe('Updated')
            ->and($payload['triggerReason'] ?? null)->toBe('MeterValueClock')
            ->and($payload['evse'] ?? null)->toBe(['id' => 1, 'connectorId' => 1])
            ->and($payload['transactionInfo']['transactionId'] ?? null)->toBeString()
            ->and($sampledValue['context'] ?? null)->toBe('Sample.Clock')
            ->and($sampledValue['measurand'] ?? null)->toBe('Energy.Active.Import.Register');
    } finally {
        if ($app !== null) {
            stopProcess($app);
        }
        if ($worker !== null) {
            stopProcess($worker);
        }
        stopProcess($mockCsms);
        foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
})->group('integration');

it('sends a current sequenced TransactionEvent after accepting its trigger', function (): void {
    $GLOBALS['capturedMockCsmsOutput'] = '';
    $GLOBALS['testProcesses'] = [];
    $directory = sys_get_temp_dir() . '/charger-trigger-transaction-' . bin2hex(random_bytes(6));
    mkdir($directory, 0775, true);
    $databasePath = $directory . '/emulator.sqlite';
    $mockCsmsPort = findOpenPort();
    $appPort = findOpenPort();
    $mockCsms = startProcess([PHP_BINARY, __DIR__ . '/../bin/mock-csms.php'], [
        'PATH' => (string) getenv('PATH'),
        'MOCK_CSMS_PORT' => (string) $mockCsmsPort,
        'MOCK_CSMS_HEARTBEAT_INTERVAL' => '30',
        'MOCK_CSMS_SEND_TRIGGER_TRANSACTION_EVENT' => '1',
    ]);
    $worker = null;
    $app = null;

    try {
        waitUntilAvailable($mockCsmsPort);
        $worker = startProcess([PHP_BINARY, __DIR__ . '/../bin/station-worker.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilDatabaseReady($databasePath);
        $app = startProcess([PHP_BINARY, '-S', "127.0.0.1:{$appPort}", '-t', __DIR__ . '/../public', __DIR__ . '/../public/router.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilAvailable($appPort);

        [$stateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $state = is_string($stateBody) ? json_decode($stateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $chargerId = (int) ($state['chargers'][0]['id'] ?? 0);
        $chargerPayload = json_encode([
            'charger_id' => $chargerId,
            'uses_default_csms' => false,
            'station_identity' => 'TriggerTransactionCharger',
            'csms_url' => "ws://127.0.0.1:{$mockCsmsPort}/ocpp",
            'vendor_name' => 'Test Vendor',
            'model' => 'Trigger Transaction Test Model',
            'serial_number' => 'TRIGGER-TX-001',
            'outlet_count' => 1,
            'outlet_topology' => 'independent',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        [, $saveHeaders] = request("http://127.0.0.1:{$appPort}/api/charger", 'POST', $chargerPayload, 'application/json');
        expect(!str_contains($saveHeaders[0] ?? '', '200'))->toBeFalse('The test charger should save before booting.');

        [$bootBody, $bootHeaders] = request("http://127.0.0.1:{$appPort}/api/boot", 'POST', $chargerPayload, 'application/json');
        expect(!str_contains($bootHeaders[0] ?? '', '200'))->toBeFalse('The test charger should complete BootNotification: ' . (string) $bootBody);
        drainProcessOutput($mockCsms);

        [$plugBody, $plugHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/outlet/plug-in",
            'POST',
            json_encode(['charger_id' => $chargerId, 'outlet_number' => 1], JSON_THROW_ON_ERROR),
            'application/json',
        );
        expect(!str_contains($plugHeaders[0] ?? '', '200'))->toBeFalse('The simulated vehicle should start a transaction: ' . (string) $plugBody);
        $csmsOutput = (string) ($GLOBALS['capturedMockCsmsOutput'] ?? '');
        if (!str_contains($csmsOutput, 'TriggerMessage transaction event: ')) {
            $csmsOutput .= waitForProcessOutput($mockCsms, 'TriggerMessage transaction event: ', 3_000);
        }

        expect($csmsOutput)->toContain('"requestedMessage":"TransactionEvent"')
            ->toContain('TriggerMessage station response: [3,"mock-csms-trigger-transaction-event-')
            ->toContain('"status":"Accepted"')
            ->toContain('TriggerMessage transaction event: [2,');

        $triggerResponsePosition = strpos($csmsOutput, 'TriggerMessage station response:');
        $triggeredEventPosition = strpos($csmsOutput, 'TriggerMessage transaction event:');
        expect($triggerResponsePosition)->toBeInt()
            ->and($triggeredEventPosition)->toBeInt()
            ->and($triggerResponsePosition)->toBeLessThan($triggeredEventPosition, 'The TriggerMessage response must precede the TransactionEvent.');

        $triggerEventLineStart = strpos($csmsOutput, 'TriggerMessage transaction event: [');
        $triggerEventFrameText = substr($csmsOutput, $triggerEventLineStart + strlen('TriggerMessage transaction event: '));
        $triggerEventFrame = json_decode(strtok($triggerEventFrameText, "\n"), true, flags: JSON_THROW_ON_ERROR);
        $triggerEvent = $triggerEventFrame[3] ?? [];
        $startedState = is_string($plugBody) ? json_decode($plugBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $transactionId = $startedState['result']['transactionId'] ?? null;
        expect($triggerEventFrame[2] ?? null)->toBe('TransactionEvent')
            ->and($triggerEvent['eventType'] ?? null)->toBe('Updated')
            ->and($triggerEvent['triggerReason'] ?? null)->toBe('Trigger')
            ->and($triggerEvent['seqNo'] ?? null)->toBe(1)
            ->and($triggerEvent['transactionInfo']['transactionId'] ?? null)->toBe($transactionId)
            ->and($triggerEvent['transactionInfo']['chargingState'] ?? null)->toBe('EVConnected')
            ->and($triggerEvent['evse'] ?? null)->toBe(['id' => 1, 'connectorId' => 1])
            ->and($triggerEvent['meterValue'][0]['sampledValue'][0]['context'] ?? null)->toBe('Trigger');

        $database = new PDO('sqlite:' . $databasePath);
        $sessionSequence = (int) $database->query(
            "SELECT seq_no FROM outlet_session WHERE charger_id = {$chargerId} AND evse_id = 1 AND connector_id = 1",
        )->fetchColumn();
        $deliveredStatus = $database->query(
            "SELECT status FROM transaction_event_outbox WHERE charger_id = {$chargerId} AND transaction_id = "
                . $database->quote((string) $transactionId) . ' AND seq_no = 1',
        )->fetchColumn();
        expect($sessionSequence)->toBe(1)
            ->and($deliveredStatus)->toBe('delivered');
    } finally {
        if ($app !== null) {
            stopProcess($app);
        }
        if ($worker !== null) {
            stopProcess($worker);
        }
        stopProcess($mockCsms);
        foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
})->group('integration');

it('reconnects with a RemoteReset BootNotification after an idle Immediate reset', function (): void {
    $GLOBALS['capturedMockCsmsOutput'] = '';
    $GLOBALS['testProcesses'] = [];
    $directory = sys_get_temp_dir() . '/charger-reset-immediate-' . bin2hex(random_bytes(6));
    mkdir($directory, 0775, true);
    $databasePath = $directory . '/emulator.sqlite';
    $mockCsmsPort = findOpenPort();
    $appPort = findOpenPort();
    $resetSentPath = $directory . '/reset-sent';
    $mockCsms = startProcess([PHP_BINARY, __DIR__ . '/../bin/mock-csms.php'], [
        'PATH' => (string) getenv('PATH'),
        'MOCK_CSMS_PORT' => (string) $mockCsmsPort,
        'MOCK_CSMS_HEARTBEAT_INTERVAL' => '30',
        'MOCK_CSMS_SEND_RESET' => '1',
        'MOCK_CSMS_RESET_SENT_PATH' => $resetSentPath,
        'MOCK_CSMS_SEND_NETWORK_PROFILE' => '1',
        'MOCK_CSMS_NETWORK_PROFILE_URL' => "ws://127.0.0.1:{$mockCsmsPort}/backup",
    ]);
    $worker = null;
    $app = null;

    try {
        waitUntilAvailable($mockCsmsPort);
        $worker = startProcess([PHP_BINARY, __DIR__ . '/../bin/station-worker.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilDatabaseReady($databasePath);
        $app = startProcess([PHP_BINARY, '-S', "127.0.0.1:{$appPort}", '-t', __DIR__ . '/../public', __DIR__ . '/../public/router.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilAvailable($appPort);

        [$stateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $state = is_string($stateBody) ? json_decode($stateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $chargerId = (int) ($state['chargers'][0]['id'] ?? 0);
        $chargerPayload = json_encode([
            'charger_id' => $chargerId,
            'uses_default_csms' => false,
            'station_identity' => 'ResetIntegrationCharger',
            'csms_url' => "ws://127.0.0.1:{$mockCsmsPort}/ocpp",
            'vendor_name' => 'Test Vendor',
            'model' => 'Reset Test Model',
            'serial_number' => 'RESET-001',
            'outlet_count' => 1,
            'outlet_topology' => 'independent',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        [, $saveHeaders] = request("http://127.0.0.1:{$appPort}/api/charger", 'POST', $chargerPayload, 'application/json');
        expect(!str_contains($saveHeaders[0] ?? '', '200'))->toBeFalse('The test charger should save before booting.');

        [, $bootHeaders] = request("http://127.0.0.1:{$appPort}/api/boot", 'POST', $chargerPayload, 'application/json');
        expect(!str_contains($bootHeaders[0] ?? '', '200'))->toBeFalse('The CSMS should accept the initial boot.');

        $resetResponseOutput = waitForProcessOutput($mockCsms, 'Reset station response: ', 3_000);
        expect($resetResponseOutput)->toContain('Reset station response: ')
            ->toContain('"status":"Accepted"');

        $resetBootOutput = waitForProcessOutput($mockCsms, 'RemoteReset', 4_000);
        expect($resetBootOutput)->toContain('RemoteReset');
        expect((string) ($GLOBALS['capturedMockCsmsOutput'] ?? ''))
            ->toContain('Connected GET /backup/ResetIntegrationCharger');
        waitForBootStatus($appPort, $chargerId, 'Accepted', 3_000);
    } finally {
        if (is_resource($app)) {
            stopProcess($app);
        }
        if (is_resource($worker)) {
            stopProcess($worker);
        }
        stopProcess($mockCsms);
        foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $resetSentPath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
})->group('integration');

it('falls back to the last successful profile when the prioritized profile cannot connect', function (): void {
    $GLOBALS['capturedMockCsmsOutput'] = '';
    $GLOBALS['testProcesses'] = [];
    $directory = sys_get_temp_dir() . '/charger-network-profile-fallback-' . bin2hex(random_bytes(6));
    mkdir($directory, 0775, true);
    $databasePath = $directory . '/emulator.sqlite';
    $mockCsmsPort = findOpenPort();
    $unreachablePort = findOpenPort();
    $appPort = findOpenPort();
    $resetSentPath = $directory . '/reset-sent';
    $mockCsms = startProcess([PHP_BINARY, __DIR__ . '/../bin/mock-csms.php'], [
        'PATH' => (string) getenv('PATH'),
        'MOCK_CSMS_PORT' => (string) $mockCsmsPort,
        'MOCK_CSMS_HEARTBEAT_INTERVAL' => '30',
        'MOCK_CSMS_SEND_RESET' => '1',
        'MOCK_CSMS_RESET_SENT_PATH' => $resetSentPath,
        'MOCK_CSMS_SEND_NETWORK_PROFILE' => '1',
        'MOCK_CSMS_NETWORK_PROFILE_URL' => "ws://127.0.0.1:{$unreachablePort}/unreachable",
        'MOCK_CSMS_NETWORK_PRIORITY' => '1',
        'MOCK_CSMS_NETWORK_PROFILE_ATTEMPTS' => '1',
    ]);
    $worker = null;
    $app = null;

    try {
        waitUntilAvailable($mockCsmsPort);
        $worker = startProcess([PHP_BINARY, __DIR__ . '/../bin/station-worker.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilDatabaseReady($databasePath);
        $app = startProcess([PHP_BINARY, '-S', "127.0.0.1:{$appPort}", '-t', __DIR__ . '/../public', __DIR__ . '/../public/router.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilAvailable($appPort);

        [$stateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $state = is_string($stateBody) ? json_decode($stateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $chargerId = (int) ($state['chargers'][0]['id'] ?? 0);
        $chargerPayload = json_encode([
            'charger_id' => $chargerId,
            'uses_default_csms' => false,
            'station_identity' => 'ProfileFallbackCharger',
            'csms_url' => "ws://127.0.0.1:{$mockCsmsPort}/ocpp",
            'vendor_name' => 'Test Vendor',
            'model' => 'Network Profile Fallback Model',
            'serial_number' => 'PROFILE-FALLBACK-001',
            'outlet_count' => 1,
            'outlet_topology' => 'independent',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        [, $saveHeaders] = request("http://127.0.0.1:{$appPort}/api/charger", 'POST', $chargerPayload, 'application/json');
        expect(!str_contains($saveHeaders[0] ?? '', '200'))->toBeFalse('The test charger should save before booting.');
        [, $bootHeaders] = request("http://127.0.0.1:{$appPort}/api/boot", 'POST', $chargerPayload, 'application/json');
        expect(!str_contains($bootHeaders[0] ?? '', '200'))->toBeFalse('The CSMS should accept the initial boot.');

        waitForProcessOutput($mockCsms, 'Reset station response: ', 3_000);
        $fallbackOutput = waitForProcessOutput($mockCsms, 'Connected GET /ocpp/ProfileFallbackCharger', 10_000);
        waitForBootStatus($appPort, $chargerId, 'Accepted', 3_000);
        $output = $fallbackOutput . (string) ($GLOBALS['capturedMockCsmsOutput'] ?? '');
        expect(substr_count($output, 'Connected GET /ocpp/ProfileFallbackCharger'))
            ->toBeGreaterThanOrEqual(2, 'After slot 1 fails, the station should reconnect with its last successful slot 0 profile.');
    } finally {
        if (is_resource($app)) {
            stopProcess($app);
        }
        if (is_resource($worker)) {
            stopProcess($worker);
        }
        stopProcess($mockCsms);
        foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $resetSentPath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
})->group('integration');

it('honors the requested reset timing with an active transaction ({type})', function (
    string $resetType,
    string $expectedStatus,
    string $expectedStoppedReason,
): void {
    $GLOBALS['capturedMockCsmsOutput'] = '';
    $GLOBALS['testProcesses'] = [];
    $directory = sys_get_temp_dir() . '/charger-reset-on-idle-' . bin2hex(random_bytes(6));
    mkdir($directory, 0775, true);
    $databasePath = $directory . '/emulator.sqlite';
    $mockCsmsPort = findOpenPort();
    $appPort = findOpenPort();
    $resetSentPath = $directory . '/reset-sent';
    $remoteStartSentPath = $directory . '/remote-start-sent';
    $mockCsms = startProcess([PHP_BINARY, __DIR__ . '/../bin/mock-csms.php'], [
        'PATH' => (string) getenv('PATH'),
        'MOCK_CSMS_PORT' => (string) $mockCsmsPort,
        'MOCK_CSMS_HEARTBEAT_INTERVAL' => '30',
        'MOCK_CSMS_SEND_CALL' => '1',
        'MOCK_CSMS_SEND_REMOTE_START' => '1',
        'MOCK_CSMS_REMOTE_STOP_BEFORE_PLUG' => '1',
        'MOCK_CSMS_SEND_RESET_AFTER_REMOTE_START' => '1',
        'MOCK_CSMS_RESET_TYPE' => $resetType,
        'MOCK_CSMS_RESET_SENT_PATH' => $resetSentPath,
        'MOCK_CSMS_REMOTE_START_SENT_PATH' => $remoteStartSentPath,
    ]);
    $worker = null;
    $app = null;

    try {
        waitUntilAvailable($mockCsmsPort);
        $worker = startProcess([PHP_BINARY, __DIR__ . '/../bin/station-worker.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilDatabaseReady($databasePath);
        $app = startProcess([PHP_BINARY, '-S', "127.0.0.1:{$appPort}", '-t', __DIR__ . '/../public', __DIR__ . '/../public/router.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilAvailable($appPort);

        [$stateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $state = is_string($stateBody) ? json_decode($stateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $chargerId = (int) ($state['chargers'][0]['id'] ?? 0);
        $chargerPayload = json_encode([
            'charger_id' => $chargerId,
            'uses_default_csms' => false,
            'station_identity' => 'ResetOnIdleCharger',
            'csms_url' => "ws://127.0.0.1:{$mockCsmsPort}/ocpp",
            'vendor_name' => 'Test Vendor',
            'model' => 'Reset Test Model',
            'serial_number' => 'RESET-IDLE-001',
            'outlet_count' => 1,
            'outlet_topology' => 'independent',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        [, $saveHeaders] = request("http://127.0.0.1:{$appPort}/api/charger", 'POST', $chargerPayload, 'application/json');
        expect(!str_contains($saveHeaders[0] ?? '', '200'))->toBeFalse('The test charger should save before booting.');
        [, $bootHeaders] = request("http://127.0.0.1:{$appPort}/api/boot", 'POST', $chargerPayload, 'application/json');
        expect(!str_contains($bootHeaders[0] ?? '', '200'))->toBeFalse('The CSMS should accept the initial boot.');

        $resetOutput = waitForProcessOutput($mockCsms, 'RemoteReset', 5_000);
        expect($resetOutput)->toContain('RemoteStart TransactionEvent: ')
            ->toContain('Reset station response: ')
            ->toContain('"status":"' . $expectedStatus . '"')
            ->toContain('RemoteReset');
        waitForBootStatus($appPort, $chargerId, 'Accepted', 3_000);

        [$resetStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $resetState = is_string($resetStateBody) ? json_decode($resetStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $entries = $resetState['chargers'][0]['entries'] ?? [];
        $resetOutlet = $resetState['chargers'][0]['outlets'][0] ?? [];
        $endedEntries = array_values(array_filter($entries, static function (array $entry): bool {
            if (($entry['action'] ?? null) !== 'TransactionEvent' || ($entry['direction'] ?? null) !== 'outgoing') {
                return false;
            }

            $call = json_decode($entry['message'], true);

            return ($call[3]['eventType'] ?? null) === 'Ended';
        }));
        $resetBootEntries = array_values(array_filter($entries, static function (array $entry): bool {
            if (($entry['action'] ?? null) !== 'BootNotification' || ($entry['direction'] ?? null) !== 'outgoing') {
                return false;
            }

            $call = json_decode($entry['message'], true);

            return ($call[3]['reason'] ?? null) === 'RemoteReset';
        }));
        $endedCall = json_decode($endedEntries[0]['message'] ?? 'null', true);
        $endedPosition = array_search($endedEntries[0] ?? null, $entries, true);
        $resetBootPosition = array_search($resetBootEntries[0] ?? null, $entries, true);

        expect($endedEntries)->toHaveCount(1)
            ->and($endedCall[3]['transactionInfo']['stoppedReason'] ?? null)->toBe($expectedStoppedReason)
            ->and($resetBootEntries)->not->toBeEmpty()
            ->and($resetOutlet['connector_status'] ?? null)->toBe('Available')
            ->and($resetOutlet['can_start_transaction'] ?? null)->toBeTrue()
            ->and($endedPosition)->toBeLessThan($resetBootPosition, 'The RemoteReset BootNotification must follow the Ended event.');
    } finally {
        if (is_resource($app)) {
            stopProcess($app);
        }
        if (is_resource($worker)) {
            stopProcess($worker);
        }
        stopProcess($mockCsms);
        foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $resetSentPath, $remoteStartSentPath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
})->with([
    ['OnIdle', 'Scheduled', 'Remote'],
    ['Immediate', 'Accepted', 'ImmediateReset'],
])->group('integration');

it('preserves an OnIdle reset across worker restart without booting before the transaction ends', function (): void {
    $GLOBALS['capturedMockCsmsOutput'] = '';
    $GLOBALS['testProcesses'] = [];
    $directory = sys_get_temp_dir() . '/charger-reset-restart-' . bin2hex(random_bytes(6));
    mkdir($directory, 0775, true);
    $databasePath = $directory . '/emulator.sqlite';
    $mockCsmsPort = findOpenPort();
    $appPort = findOpenPort();
    $resetSentPath = $directory . '/reset-sent';
    $remoteStartSentPath = $directory . '/remote-start-sent';
    $continuePath = $directory . '/remote-start-continue';
    $mockCsms = startProcess([PHP_BINARY, __DIR__ . '/../bin/mock-csms.php'], [
        'PATH' => (string) getenv('PATH'),
        'MOCK_CSMS_PORT' => (string) $mockCsmsPort,
        'MOCK_CSMS_HEARTBEAT_INTERVAL' => '30',
        'MOCK_CSMS_SEND_CALL' => '1',
        'MOCK_CSMS_SEND_REMOTE_START' => '1',
        'MOCK_CSMS_SEND_RESET_AFTER_REMOTE_START' => '1',
        'MOCK_CSMS_RESET_TYPE' => 'OnIdle',
        'MOCK_CSMS_RESET_SENT_PATH' => $resetSentPath,
        'MOCK_CSMS_REMOTE_START_SENT_PATH' => $remoteStartSentPath,
        'MOCK_CSMS_REMOTE_START_CONTINUE_PATH' => $continuePath,
        'MOCK_CSMS_SET_METER_SETTINGS' => '1',
    ]);
    $worker = null;
    $app = null;

    try {
        waitUntilAvailable($mockCsmsPort);
        $worker = startProcess([PHP_BINARY, __DIR__ . '/../bin/station-worker.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilDatabaseReady($databasePath);
        $app = startProcess([PHP_BINARY, '-S', "127.0.0.1:{$appPort}", '-t', __DIR__ . '/../public', __DIR__ . '/../public/router.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilAvailable($appPort);

        [$stateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $state = is_string($stateBody) ? json_decode($stateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $chargerId = (int) ($state['chargers'][0]['id'] ?? 0);
        $chargerPayload = json_encode([
            'charger_id' => $chargerId,
            'uses_default_csms' => false,
            'station_identity' => 'ResetRestartCharger',
            'csms_url' => "ws://127.0.0.1:{$mockCsmsPort}/ocpp",
            'vendor_name' => 'Test Vendor',
            'model' => 'Reset Test Model',
            'serial_number' => 'RESET-RESTART-001',
            'outlet_count' => 1,
            'outlet_topology' => 'independent',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        [, $saveHeaders] = request("http://127.0.0.1:{$appPort}/api/charger", 'POST', $chargerPayload, 'application/json');
        expect(!str_contains($saveHeaders[0] ?? '', '200'))->toBeFalse('The test charger should save before booting.');
        [, $bootHeaders] = request("http://127.0.0.1:{$appPort}/api/boot", 'POST', $chargerPayload, 'application/json');
        expect(!str_contains($bootHeaders[0] ?? '', '200'))->toBeFalse('The CSMS should accept the initial boot.');

        $resetResponseOutput = waitForProcessOutput($mockCsms, 'Reset station response: ', 5_000);
        expect($resetResponseOutput)->toContain('"status":"Scheduled"');
        $database = new PDO('sqlite:' . $databasePath);
        $persistedReset = $database->query(
            "SELECT reset_type, response_status FROM reset_request WHERE charger_id = {$chargerId}",
        )->fetch(PDO::FETCH_ASSOC);
        unset($database);
        expect($persistedReset)->toBe(['reset_type' => 'OnIdle', 'response_status' => 'Scheduled']);

        drainProcessOutput($mockCsms);
        stopProcess($worker);
        drainProcessOutput($mockCsms);
        $worker = startProcess([PHP_BINARY, __DIR__ . '/../bin/station-worker.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilDatabaseReady($databasePath);
        $reconnectOutput = waitForProcessOutput($mockCsms, 'Connected GET /ocpp/ResetRestartCharger HTTP/1.1', 4_000);
        expect($reconnectOutput)->toContain('Connected GET /ocpp/ResetRestartCharger HTTP/1.1')
            ->not->toContain('"reason":"RemoteReset"');
        [$waitingStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $waitingState = is_string($waitingStateBody) ? json_decode($waitingStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        expect($waitingState['chargers'][0]['outlets'][0]['remote_start_pending'] ?? null)->toBeTrue();

        [, $plugHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/outlet/plug-in",
            'POST',
            json_encode(['charger_id' => $chargerId, 'outlet_number' => 1], JSON_THROW_ON_ERROR),
            'application/json',
        );
        expect(!str_contains($plugHeaders[0] ?? '', '200'))->toBeFalse('The already active remote-start transaction may still receive its vehicle.');
        [, $authorizeHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/authorize",
            'POST',
            json_encode(['charger_id' => $chargerId, 'rfid_token' => '04A1B2C3D4'], JSON_THROW_ON_ERROR),
            'application/json',
        );
        expect(!str_contains($authorizeHeaders[0] ?? '', '200'))->toBeFalse('The vehicle token should be authorized before unplugging.');
        [, $unplugHeaders] = request(
            "http://127.0.0.1:{$appPort}/api/outlet/unplug",
            'POST',
            json_encode(['charger_id' => $chargerId, 'outlet_number' => 1], JSON_THROW_ON_ERROR),
            'application/json',
        );
        expect(!str_contains($unplugHeaders[0] ?? '', '200'))->toBeFalse('The active transaction should end before the reset.');

        $resetBootOutput = waitForProcessOutput($mockCsms, 'RemoteReset', 5_000);
        expect($resetBootOutput)->toContain('RemoteReset');
        waitForBootStatus($appPort, $chargerId, 'Accepted', 3_000);
        [$finalStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $finalState = is_string($finalStateBody) ? json_decode($finalStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $entries = $finalState['chargers'][0]['entries'] ?? [];
        $remoteStarted = array_values(array_filter($entries, static function (array $entry): bool {
            if (($entry['action'] ?? null) !== 'TransactionEvent' || ($entry['direction'] ?? null) !== 'outgoing') {
                return false;
            }

            $call = json_decode($entry['message'], true);

            return ($call[3]['eventType'] ?? null) === 'Started'
                && ($call[3]['triggerReason'] ?? null) === 'RemoteStart';
        }));
        $endedEvents = array_values(array_filter($entries, static function (array $entry): bool {
            if (($entry['action'] ?? null) !== 'TransactionEvent' || ($entry['direction'] ?? null) !== 'outgoing') {
                return false;
            }

            $call = json_decode($entry['message'], true);

            return ($call[3]['eventType'] ?? null) === 'Ended';
        }));
        $database = new PDO('sqlite:' . $databasePath);
        $bootCount = (int) $database->query(
            "SELECT COUNT(*) FROM exchange_log WHERE charger_id = {$chargerId} AND action = 'BootNotification' AND direction = 'outgoing'",
        )->fetchColumn();
        $pendingResetCount = (int) $database->query(
            "SELECT COUNT(*) FROM reset_request WHERE charger_id = {$chargerId}",
        )->fetchColumn();
        unset($database);

        expect($remoteStarted)->toHaveCount(1)
            ->and($endedEvents)->toHaveCount(1)
            ->and($bootCount)->toBe(2, 'The worker restart must not add a BootNotification; only RemoteReset does.')
            ->and($pendingResetCount)->toBe(0);
    } finally {
        if (is_resource($app)) {
            stopProcess($app);
        }
        if (is_resource($worker)) {
            stopProcess($worker);
        }
        stopProcess($mockCsms);
        foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $resetSentPath, $remoteStartSentPath, $continuePath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
})->group('integration');
