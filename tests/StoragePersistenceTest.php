<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\ExchangeRepository;

$directory = sys_get_temp_dir() . '/charger-emulator-' . bin2hex(random_bytes(6));
$databasePath = $directory . '/emulator.sqlite';
$database = Database::connect($databasePath);
$chargers = new ChargerRepository($database);
$logs = new ExchangeRepository($database);

$charger = [
    'station_identity' => 'TestStation',
    'csms_url' => 'ws://localhost:9000/ocpp/TestStation',
    'vendor_name' => 'Test Vendor',
    'model' => 'Test Model',
    'serial_number' => 'SERIAL-001',
    'outlet_count' => 2,
    'outlet_topology' => 'shared',
];
$chargerId = $chargers->create($charger);
$chargers->setBootStatus($chargerId, 'Accepted');
$chargers->save($chargerId, [
    'station_identity' => 'TestStation',
    'csms_url' => 'ws://localhost:9000/ocpp/TestStation',
    'vendor_name' => 'Updated Vendor',
    'model' => 'Test Model',
    'serial_number' => 'SERIAL-001',
    'outlet_count' => 2,
    'outlet_topology' => 'shared',
]);

$persistedCharger = $chargers->get($chargerId);
if ($persistedCharger['station_identity'] !== 'TestStation'
    || $persistedCharger['vendor_name'] !== 'Updated Vendor'
    || $persistedCharger['boot_status'] !== 'Accepted'
    || (int) $persistedCharger['outlet_count'] !== 2
    || $persistedCharger['outlet_topology'] !== 'shared') {
    throw new RuntimeException('Station configuration should persist without overwriting its BootNotification status.');
}

$outgoing = '[2,"call-1","BootNotification",{"reason":"PowerUp"}]';
$incoming = '[3,"call-1",{"status":"Accepted"}]';
$logs->record($chargerId, 'outgoing', 'BootNotification', $outgoing);
$logs->record($chargerId, 'incoming', 'BootNotification', $incoming);
$recent = $logs->recent($chargerId);
if (count($recent) !== 2
    || $recent[0]['message'] !== $outgoing
    || $recent[1]['message'] !== $incoming) {
    throw new RuntimeException('The exchange log should persist OCPP messages in chronological order.');
}

unset($logs, $chargers, $database);
foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
    if (is_file($path)) {
        unlink($path);
    }
}
rmdir($directory);

echo "SQLite persistence: PASS\n";
