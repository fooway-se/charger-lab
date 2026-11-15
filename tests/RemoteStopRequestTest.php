<?php

declare(strict_types=1);

use ChargerEmulator\Domain\RemoteStopRequestHandler;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\OcppCommandRepository;
use ChargerEmulator\Storage\OutletSessionRepository;

it('accepts a remote stop only for an active transaction and queues its outlet', function (): void {
    $directory = sys_get_temp_dir() . '/charger-remote-stop-' . bin2hex(random_bytes(6));
    $databasePath = $directory . '/emulator.sqlite';
    $database = Database::connect($databasePath);
    $chargerId = (new ChargerRepository($database))->createDefault();
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, [['number' => 1, 'evse_id' => 1, 'connector_id' => 1]]);
    $sessions->markOccupied($chargerId, 1, 1, 'active-transaction');
    $handler = new RemoteStopRequestHandler($chargerId, $sessions, new OcppCommandRepository($database));

    $result = $handler->requestStop(['transactionId' => 'active-transaction']);
    $command = (new OcppCommandRepository($database))->claimNext();

    expect($result)->toBe(['status' => 'Accepted'])
        ->and($command['action'] ?? null)->toBe('RemoteStop')
        ->and($command['payload']['transactionId'] ?? null)->toBe('active-transaction')
        ->and($command['payload']['evseId'] ?? null)->toBe(1)
        ->and($command['payload']['connectorId'] ?? null)->toBe(1)
        ->and($command['payload']['seqNo'] ?? null)->toBe(0);

    unset($handler, $sessions, $database);
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    rmdir($directory);
});

it('rejects a remote stop for an unknown transaction without queuing work', function (): void {
    $directory = sys_get_temp_dir() . '/charger-remote-stop-unknown-' . bin2hex(random_bytes(6));
    $databasePath = $directory . '/emulator.sqlite';
    $database = Database::connect($databasePath);
    $chargerId = (new ChargerRepository($database))->createDefault();
    $sessions = new OutletSessionRepository($database);
    $commands = new OcppCommandRepository($database);
    $handler = new RemoteStopRequestHandler($chargerId, $sessions, $commands);

    expect($handler->requestStop(['transactionId' => 'missing-transaction']))
        ->toBe(['status' => 'Rejected'])
        ->and($commands->claimNext())->toBeNull();

    unset($handler, $commands, $sessions, $database);
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    rmdir($directory);
});

it('accepts a remote stop for an authorization-first transaction waiting for a vehicle', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, [['number' => 1, 'evse_id' => 1, 'connector_id' => 1]]);
    $sessions->beginAuthorizationFirst(
        $chargerId,
        1,
        1,
        'authorization-first-transaction',
        'PREAUTHORIZED-TAG',
        gmdate(DATE_ATOM, time() + 30),
    );
    $commands = new OcppCommandRepository($database);
    $handler = new RemoteStopRequestHandler($chargerId, $sessions, $commands);

    expect($handler->requestStop(['transactionId' => 'authorization-first-transaction']))
        ->toBe(['status' => 'Accepted'])
        ->and($commands->claimNext()['action'] ?? null)->toBe('RemoteStop');
});
