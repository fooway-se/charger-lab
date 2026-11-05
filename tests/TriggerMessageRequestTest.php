<?php

declare(strict_types=1);

use ChargerEmulator\Domain\ChangeAvailabilityRequestHandler;
use ChargerEmulator\Domain\OutletMap;
use ChargerEmulator\Domain\TriggerMessageRequestHandler;
use ChargerEmulator\Storage\AvailabilityRepository;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\OutletSessionRepository;
use ChargerEmulator\Storage\ResetRepository;

function createTriggerMessageHandler(int $outletCount = 2, string $topology = 'shared'): array
{
    $database = Database::connect(':memory:');
    $chargers = new ChargerRepository($database);
    $chargerId = $chargers->create([
        'station_identity' => 'TriggerMessageStation',
        'csms_url' => 'ws://localhost:9000/ocpp',
        'vendor_name' => 'Test Vendor',
        'model' => 'Test Model',
        'serial_number' => '',
        'outlet_count' => $outletCount,
        'outlet_topology' => $topology,
    ]);
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, (new OutletMap($outletCount, $topology))->outlets());
    $availability = new AvailabilityRepository($database);
    $resets = new ResetRepository($database, $sessions);

    return [
        $database,
        $chargers,
        $chargerId,
        $sessions,
        $availability,
        $resets,
        new TriggerMessageRequestHandler(
            $chargerId,
            $sessions,
            $availability,
            $resets,
        ),
    ];
}

it('returns current status for only the requested EVSE connector', function (): void {
    [, , $chargerId, , $availability, , $handler] = createTriggerMessageHandler();
    (new ChangeAvailabilityRequestHandler($chargerId, $availability))->handle([
        'evse' => ['id' => 1, 'connectorId' => 2],
        'operationalStatus' => 'Inoperative',
    ]);

    $response = $handler->handle([
        'requestedMessage' => 'StatusNotification',
        'evse' => ['id' => 1, 'connectorId' => 2],
    ]);
    $notifications = $handler->takeStatusNotifications();

    expect($response)->toBe(['status' => 'Accepted'])
        ->and($notifications)->toHaveCount(1)
        ->and($notifications[0]['connectorStatus'] ?? null)->toBe('Unavailable')
        ->and($notifications[0]['evseId'] ?? null)->toBe(1)
        ->and($notifications[0]['connectorId'] ?? null)->toBe(2);
});

it('rejects an invalid StatusNotification target without queuing messages', function (): void {
    [, , , , , , $handler] = createTriggerMessageHandler();

    $response = $handler->handle([
        'requestedMessage' => 'StatusNotification',
        'evse' => ['id' => 0],
    ]);

    expect($response)->toBe(['status' => 'Rejected'])
        ->and($handler->takeStatusNotifications())->toBe([]);
});

it('reports unsupported TriggerMessage types as NotImplemented', function (): void {
    [, , , , , , $handler] = createTriggerMessageHandler();

    $response = $handler->handle(['requestedMessage' => 'MeterValues']);

    expect($response)->toBe(['status' => 'NotImplemented'])
        ->and($handler->takeStatusNotifications())->toBe([]);
});

it('rejects a triggered BootNotification after the station was accepted', function (): void {
    [, , , , , , $handler] = createTriggerMessageHandler(1, 'independent');

    expect($handler->handle(['requestedMessage' => 'BootNotification'], 'Accepted'))
        ->toBe(['status' => 'Rejected'])
        ->and($handler->takeStatusNotifications())->toBe([]);
});
