<?php

declare(strict_types=1);

use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\DeviceModelSettingsRepository;

it('preserves existing sampled data settings when adding a writable variable', function (): void {
    $directory = sys_get_temp_dir() . '/charger-device-model-migration-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $databasePath = $directory . '/emulator.sqlite';
    $database = Database::connect($databasePath);
    $chargerId = (new ChargerRepository($database))->createDefault();
    unset($database);

    $legacyDatabase = new PDO('sqlite:' . $databasePath);
    $legacyDatabase->exec('DROP TABLE device_model_setting');
    $legacyDatabase->exec(<<<'SQL'
        CREATE TABLE device_model_setting (
            charger_id INTEGER NOT NULL,
            variable TEXT NOT NULL CHECK (variable IN ('TxUpdatedInterval', 'TxUpdatedMeasurands')),
            value TEXT NOT NULL,
            PRIMARY KEY (charger_id, variable)
        )
        SQL);
    $legacyDatabase->exec("INSERT INTO device_model_setting (charger_id, variable, value) VALUES ({$chargerId}, 'TxUpdatedInterval', '7')");
    unset($legacyDatabase);

    $database = Database::connect($databasePath);
    $settings = new DeviceModelSettingsRepository($database);
    $settings->set($chargerId, 'TxStartedMeasurands', '');

    expect($settings->get($chargerId, 'TxUpdatedInterval'))->toBe('7')
        ->and($settings->get($chargerId, 'TxStartedMeasurands'))->toBe('');

    unset($settings, $database);
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    rmdir($directory);
});
