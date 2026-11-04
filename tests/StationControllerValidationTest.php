<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use ChargerEmulator\Controller\StationController;
use ChargerEmulator\Http\Request;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\ExchangeRepository;

$directory = sys_get_temp_dir() . '/charger-controller-' . bin2hex(random_bytes(6));
mkdir($directory, 0775, true);
$databasePath = $directory . '/emulator.sqlite';
$database = Database::connect($databasePath);
$controller = new StationController(new ChargerRepository($database), new ExchangeRepository($database));
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

unset($controller, $database);
foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
    if (is_file($path)) {
        unlink($path);
    }
}
rmdir($directory);

echo "Station input validation: PASS\n";
