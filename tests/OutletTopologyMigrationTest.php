<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use ChargerEmulator\Storage\Database;

$directory = sys_get_temp_dir() . '/charger-migration-' . bin2hex(random_bytes(6));
mkdir($directory, 0775, true);
$databasePath = $directory . '/emulator.sqlite';
$legacyDatabase = new PDO('sqlite:' . $databasePath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$legacyDatabase->exec(<<<'SQL'
    CREATE TABLE charger (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        station_identity TEXT NOT NULL,
        csms_url TEXT NOT NULL,
        vendor_name TEXT NOT NULL,
        model TEXT NOT NULL,
        serial_number TEXT NOT NULL DEFAULT '',
        boot_status TEXT NOT NULL DEFAULT 'Not connected',
        updated_at TEXT NOT NULL
    )
    SQL);
$legacyDatabase->exec("INSERT INTO charger (id, station_identity, csms_url, vendor_name, model, updated_at) VALUES (1, 'OldStation', 'ws://example.test', 'Legacy', 'Single Port', '2026-11-04T00:00:00Z')");
unset($legacyDatabase);

$database = Database::connect($databasePath);
$charger = $database->query('SELECT * FROM charger WHERE id = 1')->fetch();
if ($charger['station_identity'] !== 'OldStation'
    || (int) $charger['outlet_count'] !== 1
    || $charger['outlet_topology'] !== 'independent') {
    throw new RuntimeException('Existing charger settings should migrate to one independent outlet.');
}

unset($database);
foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
    if (is_file($path)) {
        unlink($path);
    }
}
rmdir($directory);

echo "Outlet topology migration: PASS\n";
