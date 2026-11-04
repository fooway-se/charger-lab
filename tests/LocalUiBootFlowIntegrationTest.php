<?php

declare(strict_types=1);

$directory = sys_get_temp_dir() . '/charger-ui-' . bin2hex(random_bytes(6));
mkdir($directory, 0775, true);
$databasePath = $directory . '/emulator.sqlite';
$fakeCsmsPort = findOpenPort();
$appPort = findOpenPort();
$overrideEndpoint = "ws://127.0.0.1:{$fakeCsmsPort}/ocpp";
$fakeCsms = startProcess([PHP_BINARY, __DIR__ . '/../bin/fake-csms.php'], [
    'PATH' => (string) getenv('PATH'),
    'FAKE_CSMS_PORT' => (string) $fakeCsmsPort,
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
    if (!is_string($page) || !str_contains($page, 'Station simulator') || !str_contains($page, '/app.js')) {
        throw new RuntimeException('The local app should serve the standalone station GUI.');
    }

    [$initialStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
    $initialState = is_string($initialStateBody) ? json_decode($initialStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
    $chargerId = (int) ($initialState['chargers'][0]['id'] ?? 0);
    if ($chargerId < 1) {
        throw new RuntimeException('The UI state should initialize a default charger.');
    }

    [$createBody, $createHeaders] = request("http://127.0.0.1:{$appPort}/api/charger/create", 'POST', '{}', 'application/json');
    $createResponse = is_string($createBody) ? json_decode($createBody, true, flags: JSON_THROW_ON_ERROR) : [];
    $secondChargerId = (int) ($createResponse['charger_id'] ?? 0);
    if (!str_contains($createHeaders[0] ?? '', '201') || $secondChargerId < 1 || $secondChargerId === $chargerId) {
        throw new RuntimeException('The local app should create an additional independent charger.');
    }

    $workspaceUrl = 'wss://workspace-csms.example/ocpp';
    [$settingsBody, $settingsHeaders] = request(
        "http://127.0.0.1:{$appPort}/api/settings/csms",
        'POST',
        json_encode(['default_csms_url' => $workspaceUrl], JSON_THROW_ON_ERROR),
        'application/json',
    );
    [$inheritedStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
    $inheritedState = is_string($inheritedStateBody) ? json_decode($inheritedStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
    if (!str_contains($settingsHeaders[0] ?? '', '200')
        || ($inheritedState['settings']['default_csms_url'] ?? null) !== $workspaceUrl
        || ($inheritedState['chargers'][0]['csms_url'] ?? null) !== $workspaceUrl
        || ($inheritedState['chargers'][1]['csms_url'] ?? null) !== $workspaceUrl) {
        throw new RuntimeException('Changing the workspace CSMS default should update every charger that inherits it.');
    }

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
    if (!str_contains($headers[0] ?? '', '200') || !is_string($body)) {
        throw new RuntimeException('The BootNotification API should return an accepted CSMS response; received ' . ($headers[0] ?? 'no HTTP status') . ' ' . (string) $body);
    }
    $bootResponse = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    if (($bootResponse['notice'] ?? null) !== 'BootNotification received: Accepted.') {
        throw new RuntimeException('The BootNotification API should report the Accepted status.');
    }

    [$stateBody, $stateHeaders] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
    if (!str_contains($stateHeaders[0] ?? '', '200')) {
        throw new RuntimeException('A bodyless state request should succeed even if the browser sends a JSON content type.');
    }
    $state = is_string($stateBody) ? json_decode($stateBody, true, flags: JSON_THROW_ON_ERROR) : [];
    $charger = $state['chargers'][0] ?? [];
    $secondCharger = $state['chargers'][1] ?? [];
    if (count($state['chargers'] ?? []) !== 2
        || ($charger['boot_status'] ?? null) !== 'Accepted'
        || ($charger['connection_status'] ?? null) !== 'Connected'
        || count($charger['entries'] ?? []) !== 2
        || ($charger['entries'][0]['action'] ?? null) !== 'BootNotification'
        || ($charger['entries'][1]['action'] ?? null) !== 'BootNotification'
        || ($charger['outlets'][0]['evse_id'] ?? null) !== 1
        || ($charger['outlets'][0]['connector_id'] ?? null) !== 1
        || ($charger['outlets'][1]['evse_id'] ?? null) !== 2
        || ($charger['outlets'][1]['connector_id'] ?? null) !== 1
        || count($secondCharger['entries'] ?? []) !== 0
        || ($secondCharger['csms_url'] ?? null) !== $workspaceUrl
        || ($charger['csms_url'] ?? null) !== $overrideEndpoint) {
        throw new RuntimeException('The local UI state should expose two independent EVSEs and the BootNotification log.');
    }

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
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    [$authorizeBody, $authorizeHeaders] = request(
        "http://127.0.0.1:{$appPort}/api/authorize",
        'POST',
        $authorizePayload,
        'application/json',
    );
    $authorizeResponse = is_string($authorizeBody) ? json_decode($authorizeBody, true, flags: JSON_THROW_ON_ERROR) : [];
    if (!str_contains($authorizeHeaders[0] ?? '', '200')
        || ($authorizeResponse['result']['idTokenInfo']['status'] ?? null) !== 'Accepted') {
        throw new RuntimeException('The RFID token form should send Authorize and show its Accepted response; received ' . ($authorizeHeaders[0] ?? 'no HTTP status') . ' ' . (string) $authorizeBody);
    }

    [$updatedStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
    $updatedState = is_string($updatedStateBody) ? json_decode($updatedStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
    $updatedCharger = $updatedState['chargers'][0] ?? [];
    $updatedSecondCharger = $updatedState['chargers'][1] ?? [];
    $authorizeCall = json_decode($updatedCharger['entries'][2]['message'] ?? 'null', true);
    if (count($updatedCharger['entries'] ?? []) !== 4
        || ($updatedCharger['entries'][2]['action'] ?? null) !== 'Authorize'
        || ($authorizeCall[3]['idToken']['idToken'] ?? null) !== '04A1B2C3D4'
        || ($authorizeCall[3]['idToken']['type'] ?? null) !== 'ISO14443'
        || count($updatedSecondCharger['entries'] ?? []) !== 0) {
        throw new RuntimeException('The local log should persist the presented RFID token as an ISO14443 Authorize call.');
    }

    $updatedWorkspaceUrl = 'wss://second-workspace-csms.example/ocpp';
    [$updatedSettingsBody, $updatedSettingsHeaders] = request(
        "http://127.0.0.1:{$appPort}/api/settings/csms",
        'POST',
        json_encode(['default_csms_url' => $updatedWorkspaceUrl], JSON_THROW_ON_ERROR),
        'application/json',
    );
    [$overrideStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
    $overrideState = is_string($overrideStateBody) ? json_decode($overrideStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
    if (!str_contains($updatedSettingsHeaders[0] ?? '', '200')
        || ($overrideState['chargers'][0]['csms_url'] ?? null) !== $overrideEndpoint
        || ($overrideState['chargers'][1]['csms_url'] ?? null) !== $updatedWorkspaceUrl) {
        throw new RuntimeException('Changing the workspace default should leave a charger override unchanged.');
    }

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
    if (!str_contains($secondBootHeaders[0] ?? '', '200')
        || ($secondBootResult['result']['status'] ?? null) !== 'Accepted') {
        throw new RuntimeException('The session worker should keep multiple charger connections active against the fake CSMS.');
    }

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
    if (!str_contains($sharedHeaders[0] ?? '', '200') || !is_string($sharedBody)) {
        throw new RuntimeException('The local API should save a shared EVSE topology.');
    }
    [$sharedStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
    $sharedState = is_string($sharedStateBody) ? json_decode($sharedStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
    $sharedCharger = $sharedState['chargers'][0] ?? [];
    if (($sharedCharger['outlets'][0]['evse_id'] ?? null) !== 1
        || ($sharedCharger['outlets'][0]['connector_id'] ?? null) !== 1
        || ($sharedCharger['outlets'][1]['evse_id'] ?? null) !== 1
        || ($sharedCharger['outlets'][1]['connector_id'] ?? null) !== 2) {
        throw new RuntimeException('Shared outlets should resolve to connector IDs 1 and 2 on EVSE 1.');
    }

    $fakeCsmsOutput = '';
    foreach ($GLOBALS['testProcesses'] as [$process, $pipes]) {
        if ($process === $fakeCsms) {
            $fakeCsmsOutput = stream_get_contents($pipes[1]);
            break;
        }
    }
    if (substr_count($fakeCsmsOutput, 'GET /ocpp/UiIntegrationCharger HTTP/1.1') !== 1) {
        throw new RuntimeException('BootNotification and Authorize should reuse one open OCPP-J session for the charger.');
    }
    if (!str_contains($fakeCsmsOutput, 'GET /ocpp/DemoCharger%202 HTTP/1.1')) {
        throw new RuntimeException('The fake CSMS should accept simultaneous station sessions with distinct identities.');
    }

    $database = new PDO('sqlite:' . $databasePath, options: [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $charger = $database->query('SELECT * FROM charger WHERE id = ' . $chargerId)->fetch();
    $messageCount = (int) $database->query('SELECT COUNT(*) FROM exchange_log')->fetchColumn();
    if ($charger['boot_status'] !== 'Accepted'
        || (int) $charger['outlet_count'] !== 2
        || $charger['outlet_topology'] !== 'shared'
        || $messageCount !== 6) {
        throw new RuntimeException('The accepted status, topology, and both OCPP exchanges should persist in SQLite.');
    }

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
    if (!str_contains($deleteHeaders[0] ?? '', '200')
        || count($remainingState['chargers'] ?? []) !== 1
        || ($remainingState['chargers'][0]['id'] ?? null) !== $secondChargerId
        || !str_contains($fakeCsmsOutput, 'Disconnected GET /ocpp/UiIntegrationCharger HTTP/1.1')) {
        throw new RuntimeException('Removing a charger should close its persistent session and preserve the other station.');
    }

    echo "Local UI BootNotification flow: PASS\n";
} finally {
    if (is_resource($app)) {
        stopProcess($app);
    }
    if (is_resource($worker)) {
        stopProcess($worker);
    }
    stopProcess($fakeCsms);
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($directory);
}

function findOpenPort(): int
{
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if ($socket === false || !socket_bind($socket, '127.0.0.1', 0) || !socket_getsockname($socket, $address, $port)) {
        throw new RuntimeException('Could not reserve an integration-test port.');
    }
    socket_close($socket);

    return $port;
}

function startProcess(array $command, array $environment)
{
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__), $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start an integration-test process.');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $GLOBALS['testProcesses'][] = [$process, $pipes];

    return $process;
}

function waitUntilAvailable(int $port): void
{
    $deadline = microtime(true) + 4;
    while (microtime(true) < $deadline) {
        $stream = @stream_socket_client("tcp://127.0.0.1:{$port}", $errorNumber, $errorMessage, 0.1);
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
