<?php

declare(strict_types=1);

$directory = sys_get_temp_dir() . '/charger-ui-' . bin2hex(random_bytes(6));
mkdir($directory, 0775, true);
$databasePath = $directory . '/emulator.sqlite';
$fakeCsmsPort = findOpenPort();
$appPort = findOpenPort();
$fakeCsms = startProcess([PHP_BINARY, __DIR__ . '/../bin/fake-csms.php'], [
    'PATH' => (string) getenv('PATH'),
    'FAKE_CSMS_PORT' => (string) $fakeCsmsPort,
]);
$app = null;

try {
    waitUntilAvailable($fakeCsmsPort);
    $app = startProcess([PHP_BINARY, '-S', "127.0.0.1:{$appPort}", '-t', __DIR__ . '/../public', __DIR__ . '/../public/router.php'], [
        'PATH' => (string) getenv('PATH'),
        'DATA_PATH' => $databasePath,
    ]);
    waitUntilAvailable($appPort);

    [$page] = request("http://127.0.0.1:{$appPort}/");
    if (!is_string($page) || !str_contains($page, 'Station simulator') || !str_contains($page, '/app.js')) {
        throw new RuntimeException('The local app should serve the standalone station GUI.');
    }

    $payload = json_encode([
        'station_identity' => 'UiIntegrationCharger',
        'csms_url' => "ws://127.0.0.1:{$fakeCsmsPort}/ocpp/UiIntegrationCharger",
        'vendor_name' => 'UI Test Vendor',
        'model' => 'UI Test Model',
        'serial_number' => 'UI-001',
        'outlet_count' => 2,
        'outlet_topology' => 'independent',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    [$body, $headers] = request("http://127.0.0.1:{$appPort}/api/boot", 'POST', $payload, 'application/json');
    if (!str_contains($headers[0] ?? '', '200') || !is_string($body)) {
        throw new RuntimeException('The BootNotification API should return an accepted CSMS response.');
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
    if (($state['charger']['boot_status'] ?? null) !== 'Accepted'
        || count($state['entries'] ?? []) !== 2
        || ($state['entries'][0]['action'] ?? null) !== 'BootNotification'
        || ($state['entries'][1]['action'] ?? null) !== 'BootNotification'
        || ($state['outlets'][0]['evse_id'] ?? null) !== 1
        || ($state['outlets'][0]['connector_id'] ?? null) !== 1
        || ($state['outlets'][1]['evse_id'] ?? null) !== 2
        || ($state['outlets'][1]['connector_id'] ?? null) !== 1) {
        throw new RuntimeException('The local UI state should expose two independent EVSEs and the BootNotification log.');
    }

    $authorizePayload = json_encode([
        'station_identity' => 'UiIntegrationCharger',
        'csms_url' => "ws://127.0.0.1:{$fakeCsmsPort}/ocpp/UiIntegrationCharger",
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
        throw new RuntimeException('The RFID token form should send Authorize and show its Accepted response.');
    }

    [$updatedStateBody] = request("http://127.0.0.1:{$appPort}/api/state", 'GET', '', 'application/json');
    $updatedState = is_string($updatedStateBody) ? json_decode($updatedStateBody, true, flags: JSON_THROW_ON_ERROR) : [];
    $authorizeCall = json_decode($updatedState['entries'][2]['message'] ?? 'null', true);
    if (count($updatedState['entries'] ?? []) !== 4
        || ($updatedState['entries'][2]['action'] ?? null) !== 'Authorize'
        || ($authorizeCall[3]['idToken']['idToken'] ?? null) !== '04A1B2C3D4'
        || ($authorizeCall[3]['idToken']['type'] ?? null) !== 'ISO14443') {
        throw new RuntimeException('The local log should persist the presented RFID token as an ISO14443 Authorize call.');
    }

    $sharedTopology = json_encode([
        'station_identity' => 'UiIntegrationCharger',
        'csms_url' => "ws://127.0.0.1:{$fakeCsmsPort}/ocpp/UiIntegrationCharger",
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
    if (($sharedState['outlets'][0]['evse_id'] ?? null) !== 1
        || ($sharedState['outlets'][0]['connector_id'] ?? null) !== 1
        || ($sharedState['outlets'][1]['evse_id'] ?? null) !== 1
        || ($sharedState['outlets'][1]['connector_id'] ?? null) !== 2) {
        throw new RuntimeException('Shared outlets should resolve to connector IDs 1 and 2 on EVSE 1.');
    }

    $database = new PDO('sqlite:' . $databasePath, options: [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $charger = $database->query('SELECT * FROM charger WHERE id = 1')->fetch();
    $messageCount = (int) $database->query('SELECT COUNT(*) FROM exchange_log')->fetchColumn();
    if ($charger['boot_status'] !== 'Accepted'
        || (int) $charger['outlet_count'] !== 2
        || $charger['outlet_topology'] !== 'shared'
        || $messageCount !== 4) {
        throw new RuntimeException('The accepted status, topology, and both OCPP exchanges should persist in SQLite.');
    }

    echo "Local UI BootNotification flow: PASS\n";
} finally {
    if (is_resource($app)) {
        stopProcess($app);
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
