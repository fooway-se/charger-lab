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
            $GLOBALS['capturedFakeCsmsOutput'] = ($GLOBALS['capturedFakeCsmsOutput'] ?? '') . $output;

            return $output;
        }

        usleep(20_000);
    }

    $GLOBALS['capturedFakeCsmsOutput'] = ($GLOBALS['capturedFakeCsmsOutput'] ?? '') . $output;

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
            $GLOBALS['capturedFakeCsmsOutput'] = ($GLOBALS['capturedFakeCsmsOutput'] ?? '') . $output;

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
    $directory = sys_get_temp_dir() . '/charger-ui-' . bin2hex(random_bytes(6));

    mkdir($directory, 0775, true);

    $databasePath = $directory . '/emulator.sqlite';

    $fakeCsmsPort = findOpenPort();

    $appPort = findOpenPort();

    $overrideEndpoint = "ws://127.0.0.1:{$fakeCsmsPort}/ocpp";

    $fakeCsms = startProcess([PHP_BINARY, __DIR__ . '/../bin/fake-csms.php'], [
        'PATH' => (string) getenv('PATH'),
        'FAKE_CSMS_PORT' => (string) $fakeCsmsPort,
        'FAKE_CSMS_HEARTBEAT_INTERVAL' => '1',
        'FAKE_CSMS_PENDING_BOOT_INTERVAL' => '6',
        'FAKE_CSMS_BOOT_COUNT_PATH' => $directory . '/fake-csms-boot-count',
        'FAKE_CSMS_PENDING_BOOT_COUNT' => '1',
        'FAKE_CSMS_REJECT_STATION' => 'UiIntegrationCharger',
        'FAKE_CSMS_BASIC_AUTH_STATION' => 'UiIntegrationCharger',
        'FAKE_CSMS_BASIC_AUTH_PASSWORD' => 'ui-station-secret',
        'FAKE_CSMS_SEND_CALL' => '1',
        'FAKE_CSMS_SEND_REJECTED_CALL' => '1',
        'FAKE_CSMS_SEND_REMOTE_START' => '1',
        'FAKE_CSMS_SET_METER_SETTINGS' => '1',
        'FAKE_CSMS_REMOTE_START_SENT_PATH' => $directory . '/remote-start-sent',
        'FAKE_CSMS_REMOTE_START_CONTINUE_PATH' => $directory . '/remote-start-continue',
    ]);

    $worker = null;

    $app = null;

    try {
        waitUntilAvailable($fakeCsmsPort);
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
        $rejectedCallOutput = waitForProcessOutput($fakeCsms, 'Rejected station response: ', 1_000);
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

        $incomingCallOutput = waitForProcessOutput($fakeCsms, 'Station response: ', 1_500);
        expect($incomingCallOutput)->toContain('"attributeValue":"false"');
        $setVariableOutput = waitForProcessOutput($fakeCsms, 'SetVariables station response: ', 1_500);
        $capturedDeviceModelOutput = ($GLOBALS['capturedFakeCsmsOutput'] ?? '') . $setVariableOutput;
        expect($capturedDeviceModelOutput)->toContain('"attributeStatus":"Rejected"');
        $meterSettingsOutput = (string) ($GLOBALS['capturedFakeCsmsOutput'] ?? '');
        if (!str_contains($meterSettingsOutput, 'Meter settings station response: ')) {
            $meterSettingsOutput .= waitForProcessOutput($fakeCsms, 'Meter settings station response: ', 5_000);
        }

        expect($meterSettingsOutput)->toContain('"attributeStatus":"Accepted"');
        $remoteStartOutput = (string) ($GLOBALS['capturedFakeCsmsOutput'] ?? '');
        if (!str_contains($remoteStartOutput, 'RemoteStart station response: ')) {
            $remoteStartOutput .= waitForProcessOutput($fakeCsms, 'RemoteStart station response: ', 5_000);
        }

        expect($remoteStartOutput)->toContain('"status":"Accepted"');
        expect(strpos($remoteStartOutput, 'RemoteStart station response: '))
            ->toBeLessThan(strpos($remoteStartOutput, 'RemoteStart TransactionEvent: '));
        if (!str_contains($remoteStartOutput, 'GetTransactionStatus station response: ')) {
            $remoteStartOutput .= waitForProcessOutput($fakeCsms, 'GetTransactionStatus station response: ', 5_000);
        }
        expect($remoteStartOutput)->toContain('GetTransactionStatus station response: ')
            ->and($remoteStartOutput)->toContain('"ongoingIndicator":true,"messagesInQueue":false');
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
            $remoteStartOutput .= waitForProcessOutput($fakeCsms, 'RemoteStop station response: ', 5_000);
        }

        if (!str_contains($remoteStartOutput, 'RemoteStop TransactionEvent: ')) {
            $remoteStartOutput .= waitForProcessOutput($fakeCsms, 'RemoteStop TransactionEvent: ', 5_000);
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
            || ($secondBootResult['result']['status'] ?? null) !== 'Pending')->toBeFalse('The session worker should keep multiple charger connections active against the fake CSMS.');
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
            $fakeCsms,
            'Connected GET /ocpp/UiIntegrationCharger HTTP/1.1',
            4_000,
        );
        expect($reconnectedOutput)->toContain('Connected GET /ocpp/UiIntegrationCharger HTTP/1.1');
        $offlineEventOutput = ($GLOBALS['capturedFakeCsmsOutput'] ?? '')
            . waitForProcessOutput($fakeCsms, '"offline":true', 3_000);
        expect($offlineEventOutput)->toContain('"offline":true')
            ->and($offlineEventOutput)->toContain('offline-restart-integration');
        $offlineDeliveryDatabase = new PDO('sqlite:' . $databasePath);
        $offlineDelivery = $offlineDeliveryDatabase->query(
            "SELECT status, payload FROM transaction_event_outbox WHERE id = {$offlineEvent['id']}",
        )->fetch(PDO::FETCH_ASSOC);
        expect($offlineDelivery['status'] ?? null)->toBe('delivered')
            ->and(json_decode($offlineDelivery['payload'] ?? '{}', true)['offline'] ?? null)->toBeTrue();
        unset($offlineDeliveryDatabase);

        $fakeCsmsOutput = $GLOBALS['capturedFakeCsmsOutput'] ?? '';
        foreach ($GLOBALS['testProcesses'] as [$process, $pipes]) {
            if ($process === $fakeCsms) {
                $fakeCsmsOutput .= stream_get_contents($pipes[1]);
                break;
            }
        }
        $stationConnectionCount = substr_count($fakeCsmsOutput, 'GET /ocpp/UiIntegrationCharger HTTP/1.1');
        expect($stationConnectionCount)->toBeGreaterThanOrEqual(2, 'The charger should establish a new OCPP-J session after the worker restart.');
        expect(!str_contains($fakeCsmsOutput, 'GET /ocpp/DemoCharger%202 HTTP/1.1'))->toBeFalse('The fake CSMS should accept simultaneous station sessions with distinct identities.');

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
            if ($process === $fakeCsms) {
                $fakeCsmsOutput .= stream_get_contents($pipes[1]);
                break;
            }
        }
        expect(!str_contains($deleteHeaders[0] ?? '', '200')
            || count($remainingState['chargers'] ?? []) !== 1
            || ($remainingState['chargers'][0]['id'] ?? null) !== $secondChargerId
            || !str_contains($fakeCsmsOutput, 'Disconnected GET /ocpp/UiIntegrationCharger HTTP/1.1'))->toBeFalse('Removing a charger should close its persistent session and preserve the other station.');

    } finally {
        if (is_resource($app)) {
            stopProcess($app);
        }
        if (is_resource($worker)) {
            stopProcess($worker);
        }
        stopProcess($fakeCsms);
        foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $directory . '/fake-csms-boot-count', $directory . '/remote-start-sent', $directory . '/remote-start-continue'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
})->group('integration');

it('preserves a waiting remote-start deadline across worker restart without reconnecting early or duplicating events', function (): void {
    $directory = sys_get_temp_dir() . '/charger-remote-start-restart-' . bin2hex(random_bytes(6));
    mkdir($directory, 0775, true);

    $databasePath = $directory . '/emulator.sqlite';
    $fakeCsmsPort = findOpenPort();
    $appPort = findOpenPort();
    $fakeCsms = startProcess([PHP_BINARY, __DIR__ . '/../bin/fake-csms.php'], [
        'PATH' => (string) getenv('PATH'),
        'FAKE_CSMS_PORT' => (string) $fakeCsmsPort,
        'FAKE_CSMS_HEARTBEAT_INTERVAL' => '30',
        'FAKE_CSMS_SEND_CALL' => '1',
        'FAKE_CSMS_SEND_REMOTE_START' => '1',
        'FAKE_CSMS_REMOTE_START_SENT_PATH' => $directory . '/remote-start-sent',
        'FAKE_CSMS_REMOTE_START_CONTINUE_PATH' => $directory . '/remote-start-continue',
        'FAKE_CSMS_SET_METER_SETTINGS' => '1',
    ]);
    $worker = null;
    $app = null;

    try {
        waitUntilAvailable($fakeCsmsPort);
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
            'csms_url' => "ws://127.0.0.1:{$fakeCsmsPort}/ocpp",
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

        $remoteStartOutput = (string) ($GLOBALS['capturedFakeCsmsOutput'] ?? '');
        if (!str_contains($remoteStartOutput, 'RemoteStart TransactionEvent: ')) {
            $remoteStartOutput .= waitForProcessOutput($fakeCsms, 'RemoteStart TransactionEvent: ', 5_000);
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

        drainProcessOutput($fakeCsms);
        stopProcess($worker);
        drainProcessOutput($fakeCsms);
        $worker = startProcess([PHP_BINARY, __DIR__ . '/../bin/station-worker.php'], [
            'PATH' => (string) getenv('PATH'),
            'DATA_PATH' => $databasePath,
        ]);
        waitUntilDatabaseReady($databasePath);

        $earlyReconnectOutput = waitForProcessOutput($fakeCsms, 'No early reconnect expected', 250);
        expect($earlyReconnectOutput)->not->toContain('Connected GET /ocpp/RestartPendingCharger HTTP/1.1');
        [$earlyStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $earlyState = is_string($earlyStateBody) ? json_decode($earlyStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $earlyOutlet = $earlyState['chargers'][0]['outlets'][0] ?? [];
        expect($earlyOutlet['remote_start_pending'] ?? null)->toBeTrue();

        $reconnectOutput = waitForProcessOutput($fakeCsms, 'Connected GET /ocpp/RestartPendingCharger HTTP/1.1', 3_000);
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
        stopProcess($fakeCsms);
        foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $directory . '/remote-start-sent', $directory . '/remote-start-continue'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
})->group('integration');

it('ends a remote-start transaction when the CSMS stops it before vehicle connection', function (): void {
    $directory = sys_get_temp_dir() . '/charger-remote-stop-' . bin2hex(random_bytes(6));
    mkdir($directory, 0775, true);

    $databasePath = $directory . '/emulator.sqlite';
    $fakeCsmsPort = findOpenPort();
    $appPort = findOpenPort();
    $fakeCsms = startProcess([PHP_BINARY, __DIR__ . '/../bin/fake-csms.php'], [
        'PATH' => (string) getenv('PATH'),
        'FAKE_CSMS_PORT' => (string) $fakeCsmsPort,
        'FAKE_CSMS_HEARTBEAT_INTERVAL' => '30',
        'FAKE_CSMS_SEND_CALL' => '1',
        'FAKE_CSMS_SEND_REMOTE_START' => '1',
        'FAKE_CSMS_REMOTE_STOP_BEFORE_PLUG' => '1',
        'FAKE_CSMS_REMOTE_START_SENT_PATH' => $directory . '/remote-start-sent',
        'FAKE_CSMS_REMOTE_START_CONTINUE_PATH' => $directory . '/remote-start-continue',
    ]);
    $worker = null;
    $app = null;

    try {
        waitUntilAvailable($fakeCsmsPort);
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
            'csms_url' => "ws://127.0.0.1:{$fakeCsmsPort}/ocpp",
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

        $remoteStopOutput = (string) ($GLOBALS['capturedFakeCsmsOutput'] ?? '');
        if (!str_contains($remoteStopOutput, 'RemoteStop station response: ')) {
            $remoteStopOutput .= waitForProcessOutput($fakeCsms, 'RemoteStop station response: ', 5_000);
        }
        if (!str_contains($remoteStopOutput, 'RemoteStop TransactionEvent: ')) {
            $remoteStopOutput .= waitForProcessOutput($fakeCsms, 'RemoteStop TransactionEvent: ', 5_000);
        }

        expect($remoteStopOutput)->toContain('RemoteStart TransactionEvent: ')
            ->and($remoteStopOutput)->toContain('RemoteStop station response: ')
            ->and($remoteStopOutput)->toContain('"status":"Accepted"')
            ->and($remoteStopOutput)->toContain('RemoteStop TransactionEvent: ');

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
        expect(($outlet['connector_status'] ?? null))->toBe('Available')
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
        stopProcess($fakeCsms);
        foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $directory . '/remote-start-sent', $directory . '/remote-start-continue'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
})->group('integration');
