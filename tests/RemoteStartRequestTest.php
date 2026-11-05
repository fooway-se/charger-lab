<?php

declare(strict_types=1);

use ChargerEmulator\Domain\RemoteStartRequestHandler;
use ChargerEmulator\Domain\OutletMap;
use ChargerEmulator\Storage\AvailabilityRepository;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\OcppCommandRepository;
use ChargerEmulator\Storage\OutletSessionRepository;

it('accepts a remote start only for an available outlet and queues its token', function (): void {
    $directory = sys_get_temp_dir() . '/charger-remote-start-' . bin2hex(random_bytes(6));
    $databasePath = $directory . '/emulator.sqlite';
    $database = Database::connect($databasePath);
    $chargerId = (new ChargerRepository($database))->create([
        'station_identity' => 'RemoteStartStation',
        'csms_url' => 'ws://localhost:9000/ocpp/RemoteStartStation',
        'vendor_name' => 'Test Vendor',
        'model' => 'Test Model',
        'serial_number' => '',
        'outlet_count' => 2,
        'outlet_topology' => 'independent',
    ]);
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, [
        ['number' => 1, 'evse_id' => 1, 'connector_id' => 1],
        ['number' => 2, 'evse_id' => 2, 'connector_id' => 1],
    ]);
    $handler = new RemoteStartRequestHandler(
        $chargerId,
        $sessions,
        new OcppCommandRepository($database),
        new AvailabilityRepository($database),
    );

    $result = $handler->handle([
        'remoteStartId' => 42,
        'idToken' => ['idToken' => 'RFID-42', 'type' => 'ISO14443'],
        'evseId' => 2,
    ]);
    $command = (new OcppCommandRepository($database))->claimNext();

    expect($result)->toBe(['status' => 'Accepted'])
        ->and($command['action'] ?? null)->toBe('RemoteStart')
        ->and($command['payload']['remoteStartId'] ?? null)->toBe(42)
        ->and($command['payload']['idToken'] ?? null)->toBe(['idToken' => 'RFID-42', 'type' => 'ISO14443'])
        ->and($command['payload']['evseId'] ?? null)->toBe(2)
        ->and($command['payload']['connectorId'] ?? null)->toBe(1);

    unset($handler, $sessions, $database);
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    rmdir($directory);
});

it('rejects a remote start when the requested EVSE has no available outlet', function (): void {
    $directory = sys_get_temp_dir() . '/charger-remote-start-busy-' . bin2hex(random_bytes(6));
    $databasePath = $directory . '/emulator.sqlite';
    $database = Database::connect($databasePath);
    $chargerId = (new ChargerRepository($database))->create([
        'station_identity' => 'BusyRemoteStartStation',
        'csms_url' => 'ws://localhost:9000/ocpp/BusyRemoteStartStation',
        'vendor_name' => 'Test Vendor',
        'model' => 'Test Model',
        'serial_number' => '',
        'outlet_count' => 1,
        'outlet_topology' => 'independent',
    ]);
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, [['number' => 1, 'evse_id' => 1, 'connector_id' => 1]]);
    $sessions->markOccupied($chargerId, 1, 1, 'existing-tx');
    $commands = new OcppCommandRepository($database);
    $handler = new RemoteStartRequestHandler(
        $chargerId,
        $sessions,
        $commands,
        new AvailabilityRepository($database),
    );

    expect($handler->handle([
        'remoteStartId' => 43,
        'idToken' => ['idToken' => 'RFID-43', 'type' => 'ISO14443'],
        'evseId' => 1,
    ]))->toBe(['status' => 'Rejected'])
        ->and($commands->claimNext())->toBeNull();

    unset($handler, $commands, $sessions, $database);
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    rmdir($directory);
});

it('rejects a remote start for an inoperative EVSE', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, [['number' => 1, 'evse_id' => 1, 'connector_id' => 1]]);
    $availability = new AvailabilityRepository($database);
    $availability->requestChange($chargerId, [
        'evse' => ['id' => 1],
        'operationalStatus' => 'Inoperative',
    ]);
    $commands = new OcppCommandRepository($database);
    $handler = new RemoteStartRequestHandler($chargerId, $sessions, $commands, $availability);

    expect($handler->handle([
        'remoteStartId' => 44,
        'idToken' => ['idToken' => 'RFID-44', 'type' => 'ISO14443'],
        'evseId' => 1,
    ]))->toBe(['status' => 'Rejected'])
        ->and($commands->claimNext())->toBeNull();
});

it('blocks a new remote start while an EVSE inoperative change is scheduled', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, (new OutletMap(2, 'shared'))->outlets());
    $sessions->markOccupied($chargerId, 1, 1, 'active-transaction');
    $availability = new AvailabilityRepository($database);
    $availability->requestChange($chargerId, [
        'evse' => ['id' => 1],
        'operationalStatus' => 'Inoperative',
    ]);
    $commands = new OcppCommandRepository($database);
    $handler = new RemoteStartRequestHandler($chargerId, $sessions, $commands, $availability);

    expect($handler->handle([
        'remoteStartId' => 45,
        'idToken' => ['idToken' => 'RFID-45', 'type' => 'ISO14443'],
        'evseId' => 1,
    ]))->toBe(['status' => 'Rejected'])
        ->and($commands->claimNext())->toBeNull();
});
