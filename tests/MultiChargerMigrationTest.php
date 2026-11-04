<?php

declare(strict_types=1);

use ChargerEmulator\Storage\Database;

it('migrates legacy single charger data without loss', function (): void {
    $directory = sys_get_temp_dir() . '/charger-multi-migration-' . bin2hex(random_bytes(6));

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
            outlet_count INTEGER NOT NULL DEFAULT 1,
            outlet_topology TEXT NOT NULL DEFAULT 'independent',
            boot_status TEXT NOT NULL DEFAULT 'Not connected',
            updated_at TEXT NOT NULL
        )
        SQL);

    $legacyDatabase->exec(<<<'SQL'
        CREATE TABLE exchange_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            direction TEXT NOT NULL,
            action TEXT NOT NULL,
            message TEXT NOT NULL,
            created_at TEXT NOT NULL
        )
        SQL);

    $legacyDatabase->exec("INSERT INTO charger (id, station_identity, csms_url, vendor_name, model, boot_status, updated_at) VALUES (1, 'Legacy', 'ws://old', 'Vendor', 'Model', 'Accepted', '2026-11-04T00:00:00Z')");

    $legacyDatabase->exec("INSERT INTO exchange_log (direction, action, message, created_at) VALUES ('outgoing', 'BootNotification', '[2,\"a\",\"BootNotification\",{}]', '2026-11-04T00:00:00Z')");

    unset($legacyDatabase);

    $database = Database::connect($databasePath);

    $chargerSchema = $database->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'charger'")->fetchColumn();

    $chargerColumns = $database->query('PRAGMA table_info(charger)')->fetchAll(PDO::FETCH_COLUMN, 1);

    $logColumns = $database->query('PRAGMA table_info(exchange_log)')->fetchAll(PDO::FETCH_COLUMN, 1);

    $legacyCharger = $database->query('SELECT * FROM charger WHERE id = 1')->fetch();

    $legacyLogs = $database->query('SELECT * FROM exchange_log')->fetchAll();

    expect(str_contains((string) $chargerSchema, 'CHECK (id = 1)')
        || !in_array('boot_retry_at', $chargerColumns, true)
        || !in_array('next_transaction_sequence', $chargerColumns, true)
        || !in_array('charger_id', $logColumns, true)
        || $legacyCharger['station_identity'] !== 'Legacy'
        || $legacyCharger['boot_status'] !== 'Accepted'
        || count($legacyLogs) !== 1
        || (int) $legacyLogs[0]['charger_id'] !== 1)->toBeFalse('The single-charger database should migrate without losing its station or log.');

    unset($database);

    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    rmdir($directory);

});
