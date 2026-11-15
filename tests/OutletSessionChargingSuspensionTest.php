<?php

declare(strict_types=1);

use ChargerEmulator\Domain\OutletMap;
use ChargerEmulator\Domain\SimulatedMeter;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\OutletSessionRepository;

it('persists EV and EVSE charging suspension state on the active transaction', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $sessions->markOccupied($chargerId, 1, 1, 'suspension-transaction');
    $sessions->attachToken($chargerId, 1, 1, 'suspension-transaction', 'TEST-TAG', 1);

    $sessions->setChargingSuspension($chargerId, 1, 1, 'suspension-transaction', 'EV', 2);
    expect($sessions->find($chargerId, 1, 1)['suspended_by'] ?? null)->toBe('EV')
        ->and($sessions->find($chargerId, 1, 1)['seq_no'] ?? null)->toBe(2)
        ->and(SimulatedMeter::powerForOutlet($sessions->find($chargerId, 1, 1)))->toBe(0);

    $sessions->setChargingSuspension($chargerId, 1, 1, 'suspension-transaction', 'EVSE', 3);
    expect($sessions->find($chargerId, 1, 1)['suspended_by'] ?? null)->toBe('EVSE')
        ->and($sessions->find($chargerId, 1, 1)['seq_no'] ?? null)->toBe(3);

    $sessions->setChargingSuspension($chargerId, 1, 1, 'suspension-transaction', null, 4);
    expect($sessions->find($chargerId, 1, 1)['suspended_by'] ?? null)->toBeNull()
        ->and($sessions->find($chargerId, 1, 1)['seq_no'] ?? null)->toBe(4)
        ->and(SimulatedMeter::powerForOutlet($sessions->find($chargerId, 1, 1)))->toBe(SimulatedMeter::CHARGING_POWER_W);
});

it('rejects suspension changes outside an authorized active transaction', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());

    expect(fn () => $sessions->setChargingSuspension($chargerId, 1, 1, 'unknown', 'EV', 1))
        ->toThrow(RuntimeException::class, 'active authorized transaction');
});
