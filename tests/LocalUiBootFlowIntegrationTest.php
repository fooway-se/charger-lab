<?php

declare(strict_types=1);

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
        'FAKE_CSMS_SEND_CALL' => '1',
        'FAKE_CSMS_SEND_REJECTED_CALL' => '1',
        'FAKE_CSMS_SEND_REMOTE_START' => '1',
        'FAKE_CSMS_REMOTE_START_SENT_PATH' => $directory . '/remote-start-sent',
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
            'vendor_name' => 'UI Test Vendor',
            'model' => 'UI Test Model',
            'serial_number' => 'UI-001',
            'outlet_count' => 2,
            'outlet_topology' => 'independent',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        [$body, $headers] = request("http://127.0.0.1:{$appPort}/api/boot", 'POST', $payload, 'application/json');
        expect(!str_contains($headers[0] ?? '', '200') || !is_string($body))->toBeFalse('The BootNotification API should return an accepted CSMS response; received ' . ($headers[0] ?? 'no HTTP status') . ' ' . (string) $body);
        $bootResponse = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        expect(($bootResponse['result']['status'] ?? null) !== 'Rejected')->toBeFalse('The initial BootNotification API response should preserve the CSMS Rejected status.');

        [$pendingStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
        $pendingState = is_string($pendingStateBody) ? json_decode($pendingStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
        $pendingCharger = $pendingState['chargers'][0] ?? [];
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
        $remoteStartOutput = (string) ($GLOBALS['capturedFakeCsmsOutput'] ?? '');
        if (!str_contains($remoteStartOutput, 'RemoteStart station response: ')) {
            $remoteStartOutput .= waitForProcessOutput($fakeCsms, 'RemoteStart station response: ', 5_000);
        }

        expect($remoteStartOutput)->toContain('"status":"Accepted"');
        expect(strpos($remoteStartOutput, 'RemoteStart station response: '))
            ->toBeLessThan(strpos($remoteStartOutput, 'RemoteStart TransactionEvent: '));
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
            || count($bootEntries) !== 4
            || count($heartbeatStateEntries) < 2
            || count($serverCallEntries) !== 4
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
            || ($remoteStartPayload[3]['idToken']['idToken'] ?? null) !== 'REMOTE-42')->toBeFalse('An accepted remote start should produce a correlated Started TransactionEvent.');
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
        expect(count($transactionCalls) !== 3
            || ($startedPayload[3]['eventType'] ?? null) !== 'Started'
            || ($startedPayload[3]['triggerReason'] ?? null) !== 'CablePluggedIn'
            || ($startedPayload[3]['seqNo'] ?? null) !== 0
            || ($startedPayload[3]['meterValue'][0]['sampledValue'][0]['context'] ?? null) !== 'Transaction.Begin'
            || ($startedPayload[3]['meterValue'][0]['sampledValue'][0]['measurand'] ?? null) !== 'Energy.Active.Import.Register'
            || ($startedPayload[3]['meterValue'][0]['sampledValue'][0]['unitOfMeasure']['unit'] ?? null) !== 'Wh'
            || ($startedPayload[3]['meterValue'][0]['sampledValue'][0]['value'] ?? null) !== 0
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
            || ($chargingPayload[3]['transactionInfo']['chargingState'] ?? null) !== 'Charging')->toBeFalse('The plug-first flow should start a transaction, then attach its accepted RFID token.');

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
        $endedPayload = json_decode($finalTransactionCalls[3]['message'] ?? 'null', true);
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
            || ($endedPayload[3]['seqNo'] ?? null) !== 3
            || ($endedPayload[3]['transactionInfo']['transactionId'] ?? null) !== $pluggedOutlet['transaction_id']
            || ($endedPayload[3]['transactionInfo']['chargingState'] ?? null) !== 'Idle'
            || ($endedPayload[3]['transactionInfo']['stoppedReason'] ?? null) !== 'EVDisconnected'
            || ($endedPayload[3]['meterValue'][0]['sampledValue'][0]['context'] ?? null) !== 'Transaction.End'
            || ($endedPayload[3]['meterValue'][0]['sampledValue'][0]['measurand'] ?? null) !== 'Energy.Active.Import.Register'
            || ($endedPayload[3]['meterValue'][0]['sampledValue'][0]['unitOfMeasure']['unit'] ?? null) !== 'Wh'
            || ($endedPayload[3]['meterValue'][0]['sampledValue'][0]['value'] ?? null) !== 1000
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
        $heartbeatCount = (int) $database->query("SELECT COUNT(*) FROM exchange_log WHERE charger_id = {$chargerId} AND action = 'Heartbeat'")->fetchColumn();
        $serverCallCount = (int) $database->query("SELECT COUNT(*) FROM exchange_log WHERE charger_id = {$chargerId} AND action = 'GetVariables'")->fetchColumn();
        expect($charger['boot_status'] !== 'Accepted'
            || (int) $charger['outlet_count'] !== 2
            || $charger['outlet_topology'] !== 'shared'
            || $bootCount !== 4
            || $authorizeCount !== 2
            || $statusNotificationCount !== 12
            || $transactionEventCount !== 14
            || $heartbeatCount < 2
            || $serverCallCount !== 4)->toBeFalse('The accepted status, topology, and both OCPP exchanges should persist in SQLite: ' . json_encode([
                'boot' => $bootCount,
                'authorize' => $authorizeCount,
                'status' => $statusNotificationCount,
                'transaction' => $transactionEventCount,
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
        foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $directory . '/fake-csms-boot-count', $directory . '/remote-start-sent'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
})->group('integration');
