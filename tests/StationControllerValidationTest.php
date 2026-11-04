<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use ChargerEmulator\Controller\StationController;
use ChargerEmulator\Http\Request;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\ExchangeRepository;
use ChargerEmulator\Storage\WorkspaceSettingsRepository;

$directory = sys_get_temp_dir() . '/charger-controller-' . bin2hex(random_bytes(6));
mkdir($directory, 0775, true);
$databasePath = $directory . '/emulator.sqlite';
$database = Database::connect($databasePath);
$chargers = new ChargerRepository($database);
$controller = new StationController($chargers, new ExchangeRepository($database), new WorkspaceSettingsRepository($database));
$response = $controller->save(new Request('POST', '/api/charger', [], [], [
    'station_identity' => 'InvalidStation',
    'csms_url' => 'ws://localhost:9000/ocpp/InvalidStation',
    'vendor_name' => 'Vendor',
    'model' => '',
    'serial_number' => '',
]));
$responseBody = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
$chargerCount = (int) $database->query('SELECT COUNT(*) FROM charger')->fetchColumn();

if ($response->status !== 422 || !isset($responseBody['error']) || $chargerCount !== 0) {
    throw new RuntimeException('Invalid station details should return 422 without persisting a charger.');
}

$topologyResponse = $controller->save(new Request('POST', '/api/charger', [], [], [
    'station_identity' => 'InvalidTopology',
    'csms_url' => 'ws://localhost:9000/ocpp/InvalidTopology',
    'vendor_name' => 'Vendor',
    'model' => 'Model',
    'serial_number' => '',
    'outlet_count' => 2,
    'outlet_topology' => 'parallel',
]));
$chargerCount = (int) $database->query('SELECT COUNT(*) FROM charger')->fetchColumn();
if ($topologyResponse->status !== 422 || $chargerCount !== 0) {
    throw new RuntimeException('Unknown outlet topology values should be rejected before persistence.');
}

$firstId = $chargers->createDefault();
$secondId = $chargers->create([
    'station_identity' => 'SecondCharger',
    'csms_url' => 'ws://localhost:9000/ocpp/SecondCharger',
    'vendor_name' => 'Vendor',
    'model' => 'Model',
    'serial_number' => 'SECOND-001',
]);
$duplicateIdentityResponse = $controller->save(new Request('POST', '/api/charger', [], [], [
    'charger_id' => $firstId,
    'station_identity' => 'SecondCharger',
    'csms_url' => 'ws://localhost:9000/ocpp/DemoCharger',
    'vendor_name' => 'Vendor',
    'model' => 'Model',
    'serial_number' => 'DEMO-001',
]));
if ($duplicateIdentityResponse->status !== 422 || $chargers->get($firstId)['station_identity'] === 'SecondCharger' || $chargers->get($secondId) === null) {
    throw new RuntimeException('Duplicate station identities should return 422 without changing either charger.');
}

unset($controller, $database);
foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
    if (is_file($path)) {
        unlink($path);
    }
}
rmdir($directory);

echo "Station input validation: PASS\n";
