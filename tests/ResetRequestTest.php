<?php

declare(strict_types=1);

use ChargerEmulator\Domain\ResetRequestHandler;
use ChargerEmulator\Domain\OutletMap;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\OutletSessionRepository;
use ChargerEmulator\Storage\ResetRepository;

it('schedules a station reset until its active transaction ends', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $sessions->markOccupied($chargerId, 1, 1, 'active-reset-transaction');
    $resets = new ResetRepository($database, $sessions);
    $handler = new ResetRequestHandler($chargerId, $resets);

    $response = $handler->requestReset(['type' => 'OnIdle']);

    expect($response)->toBe(['status' => 'Scheduled'])
        ->and($resets->pending($chargerId))->toBe(['type' => 'OnIdle', 'status' => 'Scheduled']);
});

it('accepts an idle OnIdle reset and an Immediate reset with an active transaction', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $resets = new ResetRepository($database, $sessions);
    $handler = new ResetRequestHandler($chargerId, $resets);

    $idleResponse = $handler->requestReset(['type' => 'OnIdle']);
    $sessions->markOccupied($chargerId, 1, 1, 'immediate-reset-transaction');
    $immediateResponse = $handler->requestReset(['type' => 'Immediate']);

    expect($idleResponse)->toBe(['status' => 'Accepted'])
        ->and($immediateResponse)->toBe(['status' => 'Accepted'])
        ->and($resets->pending($chargerId))->toBe(['type' => 'Immediate', 'status' => 'Accepted']);
});

it('rejects unsupported EVSE-only and malformed reset requests without saving them', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $resets = new ResetRepository($database, $sessions);
    $handler = new ResetRequestHandler($chargerId, $resets);

    $unsupportedEvseResponse = $handler->requestReset(['type' => 'Immediate', 'evseId' => 1]);
    $invalidTypeResponse = $handler->requestReset(['type' => 'Soft']);

    expect($unsupportedEvseResponse)->toBe(['status' => 'Rejected'])
        ->and($invalidTypeResponse)->toBe(['status' => 'Rejected'])
        ->and($resets->pending($chargerId))->toBeNull();
});

it('preserves a scheduled reset request after reopening SQLite', function (): void {
    $directory = sys_get_temp_dir() . '/charger-reset-persistence-' . bin2hex(random_bytes(6));
    mkdir($directory, 0775, true);
    $databasePath = $directory . '/emulator.sqlite';
    $database = Database::connect($databasePath);
    $chargerId = (new ChargerRepository($database))->createDefault();
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $sessions->markOccupied($chargerId, 1, 1, 'persistent-reset-transaction');
    $resets = new ResetRepository($database, $sessions);
    $response = (new ResetRequestHandler($chargerId, $resets))->requestReset(['type' => 'OnIdle']);
    unset($resets, $sessions, $database);

    $reopenedDatabase = Database::connect($databasePath);
    $reopenedSessions = new OutletSessionRepository($reopenedDatabase);
    $reopenedResets = new ResetRepository($reopenedDatabase, $reopenedSessions);
    $pendingReset = $reopenedResets->pending($chargerId);
    unset($reopenedResets, $reopenedSessions, $reopenedDatabase);

    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($directory);

    expect($response)->toBe(['status' => 'Scheduled'])
        ->and($pendingReset)->toBe(['type' => 'OnIdle', 'status' => 'Scheduled']);
});
