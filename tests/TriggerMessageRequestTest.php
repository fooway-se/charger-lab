<?php

declare(strict_types=1);

use ChargerEmulator\Domain\ChangeAvailabilityRequestHandler;
use ChargerEmulator\Domain\OutletMap;
use ChargerEmulator\Domain\TriggerMessageRequestHandler;
use ChargerEmulator\Storage\AvailabilityRepository;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\DeviceModelSettingsRepository;
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

    $settings = new DeviceModelSettingsRepository($database);

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
            $settings,
        ),
        $settings,
    ];
}

it('returns current status for only the requested EVSE connector', function (): void {
    [, , $chargerId, , $availability, , $handler] = createTriggerMessageHandler();
    (new ChangeAvailabilityRequestHandler($chargerId, $availability))->requestChange([
        'evse' => ['id' => 1, 'connectorId' => 2],
        'operationalStatus' => 'Inoperative',
    ]);

    $response = $handler->triggerMessage([
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

    $response = $handler->triggerMessage([
        'requestedMessage' => 'StatusNotification',
        'evse' => ['id' => 0],
    ]);

    expect($response)->toBe(['status' => 'Rejected'])
        ->and($handler->takeStatusNotifications())->toBe([]);
});

it('reports unsupported TriggerMessage types as NotImplemented', function (): void {
    [, , , , , , $handler] = createTriggerMessageHandler();

    $response = $handler->triggerMessage(['requestedMessage' => 'UnsupportedMessage']);

    expect($response)->toBe(['status' => 'NotImplemented'])
        ->and($handler->takeStatusNotifications())->toBe([]);
});

it('accepts a FirmwareStatusNotification trigger when no update is active', function (): void {
    [, , , , , , $handler] = createTriggerMessageHandler();

    $response = $handler->triggerMessage(['requestedMessage' => 'FirmwareStatusNotification']);

    expect($response)->toBe(['status' => 'Accepted'])
        ->and($handler->takeFirmwareStatusNotificationRequest())->toBeTrue();
});

it('accepts a MeterValues trigger for a configured EVSE', function (): void {
    [, , $chargerId, $sessions, , , $handler] = createTriggerMessageHandler(1, 'independent');
    $sessions->markOccupied($chargerId, 1, 1, 'meter-values-transaction');
    $sessions->recordMeterSample($chargerId, 1, 1, 'meter-values-transaction', 1250, 1, gmdate(DATE_ATOM));

    $response = $handler->triggerMessage([
        'requestedMessage' => 'MeterValues',
        'evse' => ['id' => 1],
    ]);

    expect($response)->toBe(['status' => 'Accepted'])
        ->and($handler->takeMeterValueRequests())->toBe([['evse_id' => 1, 'meter_wh' => 1250, 'power_w' => 0]]);
});

it('includes active import power in a triggered MeterValues request when configured', function (): void {
    [, , $chargerId, $sessions, , , $handler, $settings] = createTriggerMessageHandler(1, 'independent');
    $sessions->markOccupied($chargerId, 1, 1, 'meter-values-power-transaction');
    $sessions->attachToken($chargerId, 1, 1, 'meter-values-power-transaction', 'POWER-TOKEN', 1);
    $sessions->recordMeterSample($chargerId, 1, 1, 'meter-values-power-transaction', 1250, 2, gmdate(DATE_ATOM));
    $settings->set($chargerId, 'Measurands', 'Energy.Active.Import.Register,Power.Active.Import');

    $response = $handler->triggerMessage(['requestedMessage' => 'MeterValues', 'evse' => ['id' => 1]]);

    expect($response)->toBe(['status' => 'Accepted'])
        ->and($handler->takeMeterValueRequests())->toBe([[
            'evse_id' => 1,
            'meter_wh' => 1250,
            'power_w' => 7200,
        ]]);
});

it('sends one current MeterValues request per EVSE when the target is absent', function (): void {
    [, , $chargerId, $sessions, , , $handler] = createTriggerMessageHandler(2, 'independent');
    $sessions->markOccupied($chargerId, 1, 1, 'first-meter-transaction');
    $sessions->recordMeterSample($chargerId, 1, 1, 'first-meter-transaction', 1250, 1, gmdate(DATE_ATOM));
    $sessions->markOccupied($chargerId, 2, 1, 'second-meter-transaction');
    $sessions->recordMeterSample($chargerId, 2, 1, 'second-meter-transaction', 2500, 1, gmdate(DATE_ATOM));

    $response = $handler->triggerMessage(['requestedMessage' => 'MeterValues']);

    expect($response)->toBe(['status' => 'Accepted'])
        ->and($handler->takeMeterValueRequests())->toBe([
            ['evse_id' => 1, 'meter_wh' => 1250, 'power_w' => 0],
            ['evse_id' => 2, 'meter_wh' => 2500, 'power_w' => 0],
        ]);
});

it('rejects MeterValues when no aligned measurands are configured', function (): void {
    [, , $chargerId, , , , $handler, $settings] = createTriggerMessageHandler(1, 'independent');
    $settings->set($chargerId, 'Measurands', '');

    $response = $handler->triggerMessage(['requestedMessage' => 'MeterValues']);

    expect($response)->toBe(['status' => 'Rejected'])
        ->and($handler->takeMeterValueRequests())->toBe([]);
});

it('reports the current combined meter register at the EVSE level for shared connectors', function (): void {
    [, , $chargerId, $sessions, , , $handler] = createTriggerMessageHandler(2, 'shared');
    $sessions->markOccupied($chargerId, 1, 1, 'first-shared-meter-transaction');
    $sessions->recordMeterSample($chargerId, 1, 1, 'first-shared-meter-transaction', 1250, 1, gmdate(DATE_ATOM));
    $sessions->markOccupied($chargerId, 1, 2, 'second-shared-meter-transaction');
    $sessions->recordMeterSample($chargerId, 1, 2, 'second-shared-meter-transaction', 2500, 1, gmdate(DATE_ATOM));

    $response = $handler->triggerMessage(['requestedMessage' => 'MeterValues', 'evse' => ['id' => 1, 'connectorId' => 2]]);

    expect($response)->toBe(['status' => 'Accepted'])
        ->and($handler->takeMeterValueRequests())->toBe([['evse_id' => 1, 'meter_wh' => 3750, 'power_w' => 0]]);
});

it('accepts a Heartbeat trigger even when the CSMS includes an irrelevant EVSE', function (): void {
    [, , , , , , $handler] = createTriggerMessageHandler(1, 'independent');

    $response = $handler->triggerMessage([
        'requestedMessage' => 'Heartbeat',
        'evse' => ['id' => 99],
    ]);

    expect($response)->toBe(['status' => 'Accepted'])
        ->and($handler->takeHeartbeatRequest())->toBeTrue()
        ->and($handler->takeHeartbeatRequest())->toBeFalse()
        ->and($handler->takeStatusNotifications())->toBe([]);
});

it('accepts a TransactionEvent trigger for a matching active transaction', function (): void {
    [, , $chargerId, $sessions, , , $handler] = createTriggerMessageHandler();
    $sessions->markOccupied($chargerId, 1, 2, 'active-shared-transaction');

    $response = $handler->triggerMessage([
        'requestedMessage' => 'TransactionEvent',
        'evse' => ['id' => 1, 'connectorId' => 2],
    ]);
    $transactionSessions = $handler->takeTransactionSessions();

    expect($response)->toBe(['status' => 'Accepted'])
        ->and($transactionSessions)->toHaveCount(1)
        ->and($transactionSessions[0]['transaction_id'] ?? null)->toBe('active-shared-transaction')
        ->and($transactionSessions[0]['evse_id'] ?? null)->toBe(1)
        ->and($transactionSessions[0]['connector_id'] ?? null)->toBe(2);
});

it('rejects a TransactionEvent trigger when its target has no active transaction', function (): void {
    [, , , , , , $handler] = createTriggerMessageHandler();

    $response = $handler->triggerMessage([
        'requestedMessage' => 'TransactionEvent',
        'evse' => ['id' => 1],
    ]);

    expect($response)->toBe(['status' => 'Rejected'])
        ->and($handler->takeTransactionSessions())->toBe([]);
});

it('schedules a TransactionEvent for every active EVSE when the request has no EVSE target', function (): void {
    [, , $chargerId, $sessions, , , $handler] = createTriggerMessageHandler(2, 'independent');
    $sessions->markOccupied($chargerId, 1, 1, 'active-first-transaction');
    $sessions->markOccupied($chargerId, 2, 1, 'active-second-transaction');

    $response = $handler->triggerMessage(['requestedMessage' => 'TransactionEvent']);
    $transactionSessions = $handler->takeTransactionSessions();

    expect($response)->toBe(['status' => 'Accepted'])
        ->and($transactionSessions)->toHaveCount(2)
        ->and(array_column($transactionSessions, 'transaction_id'))->toBe([
            'active-first-transaction',
            'active-second-transaction',
        ]);
});

it('rejects a triggered BootNotification after the station was accepted', function (): void {
    [, , , , , , $handler] = createTriggerMessageHandler(1, 'independent');

    expect($handler->triggerMessage(['requestedMessage' => 'BootNotification'], 'Accepted'))
        ->toBe(['status' => 'Rejected'])
        ->and($handler->takeStatusNotifications())->toBe([]);
});
