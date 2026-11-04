<?php

declare(strict_types=1);

use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\WorkspaceSettingsRepository;

it('persists workspace defaults and charger overrides', function (): void {
    $directory = sys_get_temp_dir() . '/charger-workspace-settings-' . bin2hex(random_bytes(6));

    mkdir($directory, 0775, true);

    $databasePath = $directory . '/emulator.sqlite';

    $legacy = new PDO('sqlite:' . $databasePath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $legacy->exec(<<<'SQL'
        CREATE TABLE charger (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            station_identity TEXT NOT NULL UNIQUE,
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

    $legacy->exec("INSERT INTO charger VALUES (1, 'Main', 'wss://csms.example/ocpp/Main', 'Vendor', 'Model', '', 1, 'independent', 'Accepted', '2026-11-04T00:00:00Z')");

    $legacy->exec("INSERT INTO charger VALUES (2, 'Override', 'wss://other.example/ocpp/Override', 'Vendor', 'Model', '', 1, 'independent', 'Not connected', '2026-11-04T00:00:00Z')");

    unset($legacy);

    $database = Database::connect($databasePath);

    $settings = new WorkspaceSettingsRepository($database);

    $default = $settings->getCsmsUrl();
    $tlsSettings = $settings->getTlsSettings();

    $settings->saveCsmsUrl('wss://new-csms.example/ocpp');

    $chargers = $database->query('SELECT id, csms_url, uses_default_csms FROM charger ORDER BY id')->fetchAll();

    expect($default !== 'wss://csms.example/ocpp'
        || $settings->getCsmsUrl() !== 'wss://new-csms.example/ocpp'
        || $tlsSettings !== ['tls_server_name' => '', 'tls_ca_certificate' => '']
        || (int) $chargers[0]['uses_default_csms'] !== 1
        || (int) $chargers[1]['uses_default_csms'] !== 0
        || $chargers[1]['csms_url'] !== 'wss://other.example/ocpp')->toBeFalse('Workspace CSMS defaults should persist while preserving individual legacy endpoints as overrides: ' . json_encode([$default, $settings->getCsmsUrl(), $chargers], JSON_THROW_ON_ERROR));

    unset($settings, $database);

    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    rmdir($directory);

});
