<?php

declare(strict_types=1);

use ChargerEmulator\Domain\ChangeAvailabilityRequestHandler;
use ChargerEmulator\Domain\OutletMap;
use ChargerEmulator\Storage\AvailabilityRepository;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\OutletSessionRepository;

it('changes and persists only the requested EVSE availability', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->create([
        'station_identity' => 'AvailabilityStation',
        'csms_url' => 'ws://localhost:9000/ocpp',
        'vendor_name' => 'Test Vendor',
        'model' => 'Test Model',
        'serial_number' => '',
        'outlet_count' => 2,
        'outlet_topology' => 'independent',
    ]);
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, (new OutletMap(2, 'independent'))->outlets());
    $availability = new AvailabilityRepository($database);
    $handler = new ChangeAvailabilityRequestHandler($chargerId, $availability);

    $response = $handler->requestChange([
        'evse' => ['id' => 1],
        'operationalStatus' => 'Inoperative',
    ]);

    expect($response)->toBe(['status' => 'Accepted'])
        ->and($availability->connectorStatus($chargerId, 1, 1, 'Available'))->toBe('Unavailable')
        ->and($availability->connectorStatus($chargerId, 2, 1, 'Available'))->toBe('Available');
});

it('schedules inoperative availability until an active EVSE transaction ends', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $sessions->markOccupied($chargerId, 1, 1, 'active-transaction');
    $availability = new AvailabilityRepository($database);
    $handler = new ChangeAvailabilityRequestHandler($chargerId, $availability);

    $response = $handler->requestChange([
        'evse' => ['id' => 1],
        'operationalStatus' => 'Inoperative',
    ]);

    expect($response)->toBe(['status' => 'Scheduled'])
        ->and($availability->connectorStatus($chargerId, 1, 1, 'Occupied'))->toBe('Occupied');

    $sessions->markAvailable($chargerId, 1, 1, 'active-transaction', 0);
    $changes = $availability->applyPendingChanges($chargerId);

    expect($changes)->toBe([['evse_id' => 1, 'connector_id' => 1, 'status' => 'Unavailable']])
        ->and($availability->connectorStatus($chargerId, 1, 1, 'Available'))->toBe('Unavailable');
});

it('preserves connector-specific inoperative state when the parent EVSE becomes operative', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    (new OutletSessionRepository($database))->initialize($chargerId, (new OutletMap(2, 'shared'))->outlets());
    $availability = new AvailabilityRepository($database);
    $handler = new ChangeAvailabilityRequestHandler($chargerId, $availability);

    $connectorResponse = $handler->requestChange([
        'evse' => ['id' => 1, 'connectorId' => 1],
        'operationalStatus' => 'Inoperative',
    ]);
    $evseResponse = $handler->requestChange([
        'evse' => ['id' => 1],
        'operationalStatus' => 'Inoperative',
    ]);
    $restoreEvseResponse = $handler->requestChange([
        'evse' => ['id' => 1],
        'operationalStatus' => 'Operative',
    ]);

    expect($connectorResponse)->toBe(['status' => 'Accepted'])
        ->and($evseResponse)->toBe(['status' => 'Accepted'])
        ->and($restoreEvseResponse)->toBe(['status' => 'Accepted'])
        ->and($availability->connectorStatus($chargerId, 1, 1, 'Available'))->toBe('Unavailable')
        ->and($availability->connectorStatus($chargerId, 1, 2, 'Available'))->toBe('Available');
});

it('keeps explicit availability state after reopening the SQLite database', function (): void {
    $directory = sys_get_temp_dir() . '/charger-availability-' . bin2hex(random_bytes(6));
    mkdir($directory, 0775, true);
    $databasePath = $directory . '/emulator.sqlite';
    $database = Database::connect($databasePath);
    $chargerId = (new ChargerRepository($database))->createDefault();
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    (new ChangeAvailabilityRequestHandler($chargerId, new AvailabilityRepository($database)))->requestChange([
        'operationalStatus' => 'Inoperative',
    ]);
    unset($sessions, $database);

    $reopenedDatabase = Database::connect($databasePath);
    $availability = new AvailabilityRepository($reopenedDatabase);
    $status = $availability->connectorStatus($chargerId, 1, 1, 'Available');
    unset($availability, $reopenedDatabase);

    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($directory);

    expect($status)->toBe('Unavailable');
});

it('keeps a scheduled availability change until the active transaction ends after reopen', function (): void {
    $directory = sys_get_temp_dir() . '/charger-scheduled-availability-' . bin2hex(random_bytes(6));
    mkdir($directory, 0775, true);
    $databasePath = $directory . '/emulator.sqlite';
    $database = Database::connect($databasePath);
    $chargerId = (new ChargerRepository($database))->createDefault();
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $sessions->markOccupied($chargerId, 1, 1, 'active-transaction');
    $response = (new ChangeAvailabilityRequestHandler(
        $chargerId,
        new AvailabilityRepository($database),
    ))->requestChange([
        'evse' => ['id' => 1],
        'operationalStatus' => 'Inoperative',
    ]);
    unset($sessions, $database);

    $reopenedDatabase = Database::connect($databasePath);
    $reopenedSessions = new OutletSessionRepository($reopenedDatabase);
    $reopenedAvailability = new AvailabilityRepository($reopenedDatabase);
    $statusWhileTransactionActive = $reopenedAvailability->connectorStatus($chargerId, 1, 1, 'Occupied');
    $reopenedSessions->markAvailable($chargerId, 1, 1, 'active-transaction', 0);
    $changes = $reopenedAvailability->applyPendingChanges($chargerId);
    $statusAfterTransaction = $reopenedAvailability->connectorStatus($chargerId, 1, 1, 'Available');
    unset($reopenedSessions, $reopenedAvailability, $reopenedDatabase);

    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($directory);

    expect($response)->toBe(['status' => 'Scheduled'])
        ->and($statusWhileTransactionActive)->toBe('Occupied')
        ->and($changes)->toBe([['evse_id' => 1, 'connector_id' => 1, 'status' => 'Unavailable']])
        ->and($statusAfterTransaction)->toBe('Unavailable');
});
