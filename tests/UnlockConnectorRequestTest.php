<?php

declare(strict_types=1);

use ChargerEmulator\Domain\OutletMap;
use ChargerEmulator\Domain\UnlockConnectorRequestHandler;
use ChargerEmulator\Protocol\OcppCallErrorException;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\OutletSessionRepository;

it('unlocks a known connector with no authorized transaction', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outlets = new OutletSessionRepository($database);
    $outlets->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $handler = new UnlockConnectorRequestHandler($chargerId, $outlets);

    expect($handler->unlock(['evseId' => 1, 'connectorId' => 1]))
        ->toBe(['status' => 'Unlocked']);
});

it('does not unlock or stop a connector with an authorized transaction', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outlets = new OutletSessionRepository($database);
    $outlets->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $outlets->markOccupied($chargerId, 1, 1, 'active-transaction');
    $handler = new UnlockConnectorRequestHandler($chargerId, $outlets);

    expect($handler->unlock(['evseId' => 1, 'connectorId' => 1]))
        ->toBe(['status' => 'OngoingAuthorizedTransaction'])
        ->and($outlets->find($chargerId, 1, 1)['transaction_id'])->toBe('active-transaction');
});

it('reports an unknown EVSE or connector', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outlets = new OutletSessionRepository($database);
    $outlets->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $handler = new UnlockConnectorRequestHandler($chargerId, $outlets);

    expect($handler->unlock(['evseId' => 2, 'connectorId' => 1]))
        ->toBe(['status' => 'UnknownConnector'])
        ->and($handler->unlock(['evseId' => 1, 'connectorId' => 2]))
        ->toBe(['status' => 'UnknownConnector']);
});

it('returns FormationViolation for a malformed UnlockConnector request', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outlets = new OutletSessionRepository($database);
    $outlets->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $handler = new UnlockConnectorRequestHandler($chargerId, $outlets);

    expect(fn () => $handler->unlock(['evseId' => 1]))
        ->toThrow(OcppCallErrorException::class, 'evseId and connectorId');
});
