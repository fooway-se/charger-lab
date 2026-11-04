<?php

declare(strict_types=1);

use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\OutletSessionRepository;

it('keeps an outlet meter register across completed sessions and database reopen', function (): void {
    $directory = sys_get_temp_dir() . '/charger-meter-' . bin2hex(random_bytes(6));
    $databasePath = $directory . '/emulator.sqlite';
    $database = Database::connect($databasePath);
    $chargerId = (new ChargerRepository($database))->create([
        'station_identity' => 'MeterStation',
        'csms_url' => 'ws://localhost:9000/ocpp/MeterStation',
        'vendor_name' => 'Test Vendor',
        'model' => 'Test Model',
        'serial_number' => '',
        'outlet_count' => 1,
        'outlet_topology' => 'independent',
    ]);
    $sessions = new OutletSessionRepository($database);
    $outlets = [['number' => 1, 'evse_id' => 1, 'connector_id' => 1]];
    $sessions->initialize($chargerId, $outlets);
    $sessions->markOccupied($chargerId, 1, 1, 'tx-1');
    $sessions->markAvailable($chargerId, 1, 1, 'tx-1', 1000);
    unset($sessions, $database);

    $database = Database::connect($databasePath);
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, $outlets);
    $session = $sessions->find($chargerId, 1, 1);

    expect($session['meter_wh'] ?? null)->toBe(1000);

    $sessions->markOccupied($chargerId, 1, 1, 'tx-2');
    $sessions->markAvailable($chargerId, 1, 1, 'tx-2', ($session['meter_wh'] ?? 0) + 1000);
    $session = $sessions->find($chargerId, 1, 1);

    expect($session['meter_wh'] ?? null)->toBe(2000);

    unset($sessions, $database);
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    rmdir($directory);
});

it('adds the meter register when upgrading an existing outlet session table', function (): void {
    $directory = sys_get_temp_dir() . '/charger-meter-migration-' . bin2hex(random_bytes(6));
    $databasePath = $directory . '/emulator.sqlite';
    $database = Database::connect($databasePath);
    $chargerId = (new ChargerRepository($database))->create([
        'station_identity' => 'LegacyMeterStation',
        'csms_url' => 'ws://localhost:9000/ocpp/LegacyMeterStation',
        'vendor_name' => 'Test Vendor',
        'model' => 'Test Model',
        'serial_number' => '',
        'outlet_count' => 1,
        'outlet_topology' => 'independent',
    ]);
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, [['number' => 1, 'evse_id' => 1, 'connector_id' => 1]]);
    $sessions->markOccupied($chargerId, 1, 1, 'legacy-tx');
    $sessions->attachToken($chargerId, 1, 1, 'legacy-tx', '04A1', 1);
    unset($sessions, $database);

    $legacyDatabase = new PDO('sqlite:' . $databasePath);
    $legacyDatabase->exec('ALTER TABLE outlet_session DROP COLUMN meter_wh');
    unset($legacyDatabase);

    $database = Database::connect($databasePath);
    $session = (new OutletSessionRepository($database))->find($chargerId, 1, 1);

    expect($session['connector_status'] ?? null)->toBe('Occupied')
        ->and($session['transaction_id'] ?? null)->toBe('legacy-tx')
        ->and($session['id_token'] ?? null)->toBe('04A1')
        ->and($session['meter_wh'] ?? null)->toBe(0);

    unset($database);
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    rmdir($directory);
});
