<?php

declare(strict_types=1);

use ChargerEmulator\Domain\DeviceModel;
use ChargerEmulator\Domain\OutletMap;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\DeviceModelSettingsRepository;
use ChargerEmulator\Storage\OutletSessionRepository;

it('reports supported device model variables and current outlet state', function (): void {
    $database = Database::connect(':memory:');
    $chargers = new ChargerRepository($database);
    $chargerId = $chargers->createDefault();
    $outletSessions = new OutletSessionRepository($database);
    $outletSessions->initialize($chargerId, (new OutletMap(2, 'independent'))->outlets());
    $deviceModel = new DeviceModel($outletSessions, new DeviceModelSettingsRepository($database), $chargerId);

    $response = $deviceModel->handle('GetVariables', [
        'getVariableData' => [
            ['component' => ['name' => 'ChargingStation'], 'variable' => ['name' => 'Available']],
            ['component' => ['name' => 'EVSE', 'evse' => ['id' => 2]], 'variable' => ['name' => 'AvailabilityState']],
            ['component' => ['name' => 'AuthCtrlr'], 'variable' => ['name' => 'AuthorizeRemoteStart']],
        ],
    ]);

    expect($response)->toBe([
        'getVariableResult' => [
            [
                'attributeStatus' => 'Accepted',
                'component' => ['name' => 'ChargingStation'],
                'variable' => ['name' => 'Available'],
                'attributeType' => 'Actual',
                'attributeValue' => 'true',
            ],
            [
                'attributeStatus' => 'Accepted',
                'component' => ['name' => 'EVSE', 'evse' => ['id' => 2]],
                'variable' => ['name' => 'AvailabilityState'],
                'attributeType' => 'Actual',
                'attributeValue' => 'Available',
            ],
            [
                'attributeStatus' => 'Accepted',
                'component' => ['name' => 'AuthCtrlr'],
                'variable' => ['name' => 'AuthorizeRemoteStart'],
                'attributeType' => 'Actual',
                'attributeValue' => 'false',
            ],
        ],
    ]);
});

it('returns Device Model status codes for unknown and read-only variables', function (): void {
    $database = Database::connect(':memory:');
    $chargers = new ChargerRepository($database);
    $chargerId = $chargers->createDefault();
    $outletSessions = new OutletSessionRepository($database);
    $outletSessions->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $deviceModel = new DeviceModel($outletSessions, new DeviceModelSettingsRepository($database), $chargerId);

    $getResponse = $deviceModel->handle('GetVariables', [
        'getVariableData' => [
            ['component' => ['name' => 'MissingComponent'], 'variable' => ['name' => 'Available']],
            ['component' => ['name' => 'ChargingStation'], 'variable' => ['name' => 'MissingVariable']],
            ['component' => ['name' => 'EVSE', 'evse' => ['id' => 9]], 'variable' => ['name' => 'AvailabilityState']],
            ['component' => ['name' => 'AuthCtrlr'], 'variable' => ['name' => 'AuthorizeRemoteStart'], 'attributeType' => 'Target'],
        ],
    ]);
    $setResponse = $deviceModel->handle('SetVariables', [
        'setVariableData' => [[
            'component' => ['name' => 'AuthCtrlr'],
            'variable' => ['name' => 'AuthorizeRemoteStart'],
            'attributeType' => 'Actual',
            'attributeValue' => 'true',
        ], [
            'component' => ['name' => 'AuthCtrlr'],
            'variable' => ['name' => 'AuthorizeRemoteStart'],
            'attributeType' => 'Target',
            'attributeValue' => 'true',
        ]],
    ]);

    expect($getResponse['getVariableResult'][0]['attributeStatus'] ?? null)->toBe('UnknownComponent');
    expect($getResponse['getVariableResult'][1]['attributeStatus'] ?? null)->toBe('UnknownVariable');
    expect($getResponse['getVariableResult'][2]['attributeStatus'] ?? null)->toBe('UnknownComponent');
    expect($getResponse['getVariableResult'][3]['attributeStatus'] ?? null)->toBe('NotSupportedAttributeType');
    expect($getResponse['getVariableResult'][3]['attributeType'] ?? null)->toBe('Target');
    expect(array_key_exists('attributeValue', $getResponse['getVariableResult'][3] ?? []))->toBeFalse();
    expect($setResponse['setVariableResult'][0]['attributeStatus'] ?? null)->toBe('Rejected');
    expect($setResponse['setVariableResult'][1]['attributeStatus'] ?? null)->toBe('NotSupportedAttributeType');
    expect($setResponse['setVariableResult'][1]['attributeType'] ?? null)->toBe('Target');
});
