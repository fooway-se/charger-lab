<?php

declare(strict_types=1);

use ChargerEmulator\Domain\DeviceModel;
use ChargerEmulator\Domain\OutletMap;
use ChargerEmulator\Storage\AvailabilityRepository;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\DeviceModelSettingsRepository;
use ChargerEmulator\Storage\OutletSessionRepository;

it('reports the mandatory Core Device Model variables in FullInventory and GetVariables', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outletSessions = new OutletSessionRepository($database);
    $outletSessions->initialize($chargerId, (new OutletMap(2, 'independent'))->outlets());
    $deviceModel = new DeviceModel(
        $outletSessions,
        new DeviceModelSettingsRepository($database),
        $chargerId,
        new AvailabilityRepository($database),
    );

    $coreVariables = [
        [['name' => 'OCPPCommCtrlr'], ['name' => 'ActiveNetworkProfile']],
        [['name' => 'OCPPCommCtrlr'], ['name' => 'MessageTimeout', 'instance' => 'Default']],
        [['name' => 'OCPPCommCtrlr'], ['name' => 'FileTransferProtocols']],
        [['name' => 'OCPPCommCtrlr'], ['name' => 'UnlockOnEVSideDisconnect']],
        [['name' => 'OCPPCommCtrlr'], ['name' => 'ResetRetries']],
        [['name' => 'DeviceDataCtrlr'], ['name' => 'ItemsPerMessage', 'instance' => 'GetReport']],
        [['name' => 'DeviceDataCtrlr'], ['name' => 'ItemsPerMessage', 'instance' => 'GetVariables']],
        [['name' => 'DeviceDataCtrlr'], ['name' => 'ItemsPerMessage', 'instance' => 'SetVariables']],
        [['name' => 'DeviceDataCtrlr'], ['name' => 'BytesPerMessage', 'instance' => 'GetReport']],
        [['name' => 'DeviceDataCtrlr'], ['name' => 'BytesPerMessage', 'instance' => 'GetVariables']],
        [['name' => 'DeviceDataCtrlr'], ['name' => 'BytesPerMessage', 'instance' => 'SetVariables']],
        [['name' => 'ClockCtrlr'], ['name' => 'DateTime']],
        [['name' => 'ClockCtrlr'], ['name' => 'TimeSource']],
        [['name' => 'SecurityCtrlr'], ['name' => 'OrganizationName']],
        [['name' => 'SecurityCtrlr'], ['name' => 'CertificateEntries']],
        [['name' => 'SecurityCtrlr'], ['name' => 'SecurityProfile']],
        [['name' => 'AuthCtrlr'], ['name' => 'LocalAuthorizeOffline']],
        [['name' => 'AuthCtrlr'], ['name' => 'LocalPreAuthorize']],
        [['name' => 'TxCtrlr'], ['name' => 'StopTxOnEVSideDisconnect']],
        [['name' => 'TxCtrlr'], ['name' => 'TxStartPoint']],
        [['name' => 'TxCtrlr'], ['name' => 'TxStopPoint']],
        [['name' => 'TxCtrlr'], ['name' => 'StopTxOnInvalidId']],
        [['name' => 'AlignedDataCtrlr'], ['name' => 'TxEndedMeasurands']],
        [['name' => 'AlignedDataCtrlr'], ['name' => 'TxEndedInterval']],
        [['name' => 'ChargingStation'], ['name' => 'SupplyPhases']],
        [['name' => 'EVSE', 'evse' => ['id' => 1]], ['name' => 'SupplyPhases']],
        [['name' => 'EVSE', 'evse' => ['id' => 1]], ['name' => 'Power']],
        [['name' => 'Connector', 'evse' => ['id' => 1, 'connectorId' => 1]], ['name' => 'ConnectorType']],
        [['name' => 'Connector', 'evse' => ['id' => 1, 'connectorId' => 1]], ['name' => 'SupplyPhases']],
    ];
    $get = $deviceModel->processVariableAction('GetVariables', [
        'getVariableData' => array_map(static fn (array $entry): array => [
            'component' => $entry[0],
            'variable' => $entry[1],
        ], $coreVariables),
    ]);
    $inventory = $deviceModel->inventory('FullInventory');
    $inventoryKeys = array_map(static fn (array $entry): string => json_encode([
        $entry['component']['name'] ?? null,
        $entry['component']['evse'] ?? null,
        $entry['variable']['name'] ?? null,
        $entry['variable']['instance'] ?? null,
    ], JSON_THROW_ON_ERROR), $inventory);

    expect(array_column($get['getVariableResult'], 'attributeStatus'))->toBe(array_fill(0, count($coreVariables), 'Accepted'));
    foreach ($coreVariables as [$component, $variable]) {
        $key = json_encode([
            $component['name'],
            $component['evse'] ?? null,
            $variable['name'],
            $variable['instance'] ?? null,
        ], JSON_THROW_ON_ERROR);
        expect($inventoryKeys)->toContain($key);
    }
});

it('persists writable Core variables including aligned and sampled end data independently', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outletSessions = new OutletSessionRepository($database);
    $outletSessions->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $settings = new DeviceModelSettingsRepository($database);
    $deviceModel = new DeviceModel($outletSessions, $settings, $chargerId, new AvailabilityRepository($database));

    $set = $deviceModel->processVariableAction('SetVariables', ['setVariableData' => [
        ['component' => ['name' => 'SecurityCtrlr'], 'variable' => ['name' => 'OrganizationName'], 'attributeValue' => 'Core Test Organization'],
        ['component' => ['name' => 'AuthCtrlr'], 'variable' => ['name' => 'LocalAuthorizeOffline'], 'attributeValue' => 'true'],
        ['component' => ['name' => 'AuthCtrlr'], 'variable' => ['name' => 'LocalPreAuthorize'], 'attributeValue' => 'true'],
        ['component' => ['name' => 'ClockCtrlr'], 'variable' => ['name' => 'TimeSource'], 'attributeValue' => 'RealTimeClock,Heartbeat'],
        ['component' => ['name' => 'OCPPCommCtrlr'], 'variable' => ['name' => 'ResetRetries'], 'attributeValue' => '4'],
        ['component' => ['name' => 'TxCtrlr'], 'variable' => ['name' => 'StopTxOnEVSideDisconnect'], 'attributeValue' => 'false'],
        ['component' => ['name' => 'TxCtrlr'], 'variable' => ['name' => 'StopTxOnInvalidId'], 'attributeValue' => 'true'],
        ['component' => ['name' => 'AlignedDataCtrlr'], 'variable' => ['name' => 'TxEndedInterval'], 'attributeValue' => '7'],
        ['component' => ['name' => 'SampledDataCtrlr'], 'variable' => ['name' => 'TxEndedInterval'], 'attributeValue' => '3'],
    ]]);

    expect(array_column($set['setVariableResult'], 'attributeStatus'))->toBe(array_fill(0, 9, 'Accepted'))
        ->and($settings->get($chargerId, 'OrganizationName'))->toBe('Core Test Organization')
        ->and($settings->get($chargerId, 'LocalAuthorizeOffline'))->toBe('true')
        ->and($settings->get($chargerId, 'LocalPreAuthorize'))->toBe('true')
        ->and($settings->get($chargerId, 'TimeSource'))->toBe('RealTimeClock,Heartbeat')
        ->and($settings->get($chargerId, 'ResetRetries'))->toBe('4')
        ->and($settings->get($chargerId, 'StopTxOnEVSideDisconnect'))->toBe('false')
        ->and($settings->get($chargerId, 'StopTxOnInvalidId'))->toBe('true')
        ->and($settings->get($chargerId, 'AlignedTxEndedInterval'))->toBe('7')
        ->and($settings->get($chargerId, 'TxEndedInterval'))->toBe('3');
});
