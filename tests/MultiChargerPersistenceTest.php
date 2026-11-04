<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\ExchangeRepository;

$directory = sys_get_temp_dir() . '/charger-multi-' . bin2hex(random_bytes(6));
mkdir($directory, 0775, true);
$databasePath = $directory . '/emulator.sqlite';
$database = Database::connect($databasePath);
$chargers = new ChargerRepository($database);
$logs = new ExchangeRepository($database);

$firstId = $chargers->create([
    'station_identity' => 'ChargerA',
    'csms_url' => 'ws://localhost:9000/ocpp/ChargerA',
    'vendor_name' => 'Vendor A',
    'model' => 'Model A',
    'serial_number' => 'A-001',
    'outlet_count' => 2,
    'outlet_topology' => 'independent',
]);
$secondId = $chargers->create([
    'station_identity' => 'ChargerB',
    'csms_url' => 'ws://localhost:9000/ocpp/ChargerB',
    'vendor_name' => 'Vendor B',
    'model' => 'Model B',
    'serial_number' => 'B-001',
    'outlet_count' => 2,
    'outlet_topology' => 'shared',
]);
$chargers->setBootStatus($firstId, 'Accepted');
$firstTransactionId = $chargers->nextTransactionId($firstId);
$secondTransactionId = $chargers->nextTransactionId($firstId);
$otherChargerTransactionId = $chargers->nextTransactionId($secondId);
$logs->record($firstId, 'outgoing', 'BootNotification', '[2,"a","BootNotification",{}]');
$logs->record($secondId, 'outgoing', 'BootNotification', '[2,"b","BootNotification",{}]');

$allChargers = $chargers->all();
$firstLogs = $logs->recent($firstId);
$secondLogs = $logs->recent($secondId);
if (count($allChargers) !== 2
    || $chargers->get($firstId)['boot_status'] !== 'Accepted'
    || $allChargers[1]['outlet_topology'] !== 'shared'
    || !preg_match('/^[a-f0-9]{32}$/', $firstTransactionId)
    || $firstTransactionId === $secondTransactionId
    || $firstTransactionId === $otherChargerTransactionId
    || count($firstLogs) !== 1
    || $firstLogs[0]['message'] !== '[2,"a","BootNotification",{}]'
    || count($secondLogs) !== 1
    || $secondLogs[0]['message'] !== '[2,"b","BootNotification",{}]') {
    throw new RuntimeException('Charger records and communication logs should remain isolated by charger ID.');
}

unset($logs, $chargers, $database);
$database = Database::connect($databasePath);
$chargers = new ChargerRepository($database);
if ($chargers->nextTransactionId($firstId) === $firstTransactionId
    || $chargers->nextTransactionId($firstId) === $secondTransactionId) {
    throw new RuntimeException('Transaction IDs should remain unique across worker restarts.');
}

unset($chargers, $database);
foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
    if (is_file($path)) {
        unlink($path);
    }
}
rmdir($directory);

echo "Multi-charger persistence: PASS\n";
