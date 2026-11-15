<?php

declare(strict_types=1);

use ChargerEmulator\Domain\DeviceModel;
use ChargerEmulator\Domain\OutletMap;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\AvailabilityRepository;
use ChargerEmulator\Storage\DeviceModelSettingsRepository;
use ChargerEmulator\Storage\NetworkProfileRepository;
use ChargerEmulator\Storage\OutletSessionRepository;

it('reports supported device model variables and current outlet state', function (): void {
    $database = Database::connect(':memory:');
    $chargers = new ChargerRepository($database);
    $chargerId = $chargers->createDefault();
    $outletSessions = new OutletSessionRepository($database);
    $outletSessions->initialize($chargerId, (new OutletMap(2, 'independent'))->outlets());
    $deviceModel = new DeviceModel(
        $outletSessions,
        new DeviceModelSettingsRepository($database),
        $chargerId,
        new AvailabilityRepository($database),
    );

    $response = $deviceModel->processVariableAction('GetVariables', [
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

it('exposes and persists OCPPCommCtrlr OfflineThreshold', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outletSessions = new OutletSessionRepository($database);
    $outletSessions->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $settings = new DeviceModelSettingsRepository($database);
    $deviceModel = new DeviceModel($outletSessions, $settings, $chargerId, new AvailabilityRepository($database));

    $getInitial = $deviceModel->processVariableAction('GetVariables', ['getVariableData' => [[
        'component' => ['name' => 'OCPPCommCtrlr'],
        'variable' => ['name' => 'OfflineThreshold'],
    ]]]);
    $set = $deviceModel->processVariableAction('SetVariables', ['setVariableData' => [[
        'component' => ['name' => 'OCPPCommCtrlr'],
        'variable' => ['name' => 'OfflineThreshold'],
        'attributeValue' => '300',
    ]]]);
    $getUpdated = $deviceModel->processVariableAction('GetVariables', ['getVariableData' => [[
        'component' => ['name' => 'OCPPCommCtrlr'],
        'variable' => ['name' => 'OfflineThreshold'],
    ]]]);
    $inventory = array_values(array_filter(
        $deviceModel->inventory('FullInventory'),
        static fn (array $entry): bool => ($entry['component']['name'] ?? null) === 'OCPPCommCtrlr'
            && ($entry['variable']['name'] ?? null) === 'OfflineThreshold',
    ));

    expect($getInitial['getVariableResult'][0]['attributeStatus'] ?? null)->toBe('Accepted')
        ->and($getInitial['getVariableResult'][0]['attributeValue'] ?? null)->toBe('300')
        ->and($set['setVariableResult'][0]['attributeStatus'] ?? null)->toBe('Accepted')
        ->and($getUpdated['getVariableResult'][0]['attributeValue'] ?? null)->toBe('300')
        ->and($settings->get($chargerId, 'OfflineThreshold'))->toBe('300')
        ->and($inventory[0]['variableCharacteristics']['dataType'] ?? null)->toBe('integer')
        ->and($inventory[0]['variableAttribute'][0]['mutability'] ?? null)->toBe('ReadWrite');
});

it('rejects invalid OCPPCommCtrlr OfflineThreshold values', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outletSessions = new OutletSessionRepository($database);
    $outletSessions->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $settings = new DeviceModelSettingsRepository($database);
    $deviceModel = new DeviceModel($outletSessions, $settings, $chargerId, new AvailabilityRepository($database));

    $set = $deviceModel->processVariableAction('SetVariables', ['setVariableData' => array_map(
        static fn (string $value): array => [
            'component' => ['name' => 'OCPPCommCtrlr'],
            'variable' => ['name' => 'OfflineThreshold'],
            'attributeValue' => $value,
        ],
        ['-1', '1.5', '01', 'not-a-number'],
    )]);

    expect(array_column($set['setVariableResult'], 'attributeStatus'))->toBe(['Rejected', 'Rejected', 'Rejected', 'Rejected'])
        ->and($settings->get($chargerId, 'OfflineThreshold'))->toBe('300');
});

it('returns Device Model status codes for unknown and read-only variables', function (): void {
    $database = Database::connect(':memory:');
    $chargers = new ChargerRepository($database);
    $chargerId = $chargers->createDefault();
    $outletSessions = new OutletSessionRepository($database);
    $outletSessions->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $deviceModel = new DeviceModel(
        $outletSessions,
        new DeviceModelSettingsRepository($database),
        $chargerId,
        new AvailabilityRepository($database),
    );

    $getResponse = $deviceModel->processVariableAction('GetVariables', [
        'getVariableData' => [
            ['component' => ['name' => 'MissingComponent'], 'variable' => ['name' => 'Available']],
            ['component' => ['name' => 'ChargingStation'], 'variable' => ['name' => 'MissingVariable']],
            ['component' => ['name' => 'EVSE', 'evse' => ['id' => 9]], 'variable' => ['name' => 'AvailabilityState']],
            ['component' => ['name' => 'AuthCtrlr'], 'variable' => ['name' => 'AuthorizeRemoteStart'], 'attributeType' => 'Target'],
        ],
    ]);
    $setResponse = $deviceModel->processVariableAction('SetVariables', [
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

it('reports inoperative state through the Device Model AvailabilityState variable', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outletSessions = new OutletSessionRepository($database);
    $outletSessions->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $availability = new AvailabilityRepository($database);
    $availability->requestChange($chargerId, [
        'evse' => ['id' => 1],
        'operationalStatus' => 'Inoperative',
    ]);
    $deviceModel = new DeviceModel(
        $outletSessions,
        new DeviceModelSettingsRepository($database),
        $chargerId,
        $availability,
    );

    $response = $deviceModel->processVariableAction('GetVariables', [
        'getVariableData' => [[
            'component' => ['name' => 'EVSE', 'evse' => ['id' => 1]],
            'variable' => ['name' => 'AvailabilityState'],
        ]],
    ]);

    expect($response['getVariableResult'][0]['attributeValue'] ?? null)->toBe('Unavailable');
});

it('accepts a unique network profile priority using supported slots', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outletSessions = new OutletSessionRepository($database);
    $outletSessions->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $networkProfiles = new NetworkProfileRepository($database);
    $networkProfiles->set($chargerId, [
        'ocppVersion' => 'OCPP20',
        'ocppTransport' => 'JSON',
        'ocppCsmsUrl' => 'ws://backup.example/ocpp',
        'messageTimeout' => 30,
        'securityProfile' => 1,
        'ocppInterface' => 'Wired0',
    ], 1);
    $deviceModel = new DeviceModel(
        $outletSessions,
        new DeviceModelSettingsRepository($database),
        $chargerId,
        new AvailabilityRepository($database),
        networkProfiles: $networkProfiles,
    );

    $response = $deviceModel->processVariableAction('SetVariables', ['setVariableData' => [[
        'component' => ['name' => 'OCPPCommCtrlr'],
        'variable' => ['name' => 'NetworkConfigurationPriority'],
        'attributeType' => 'Actual',
        'attributeValue' => '1,0',
    ], [
        'component' => ['name' => 'OCPPCommCtrlr'],
        'variable' => ['name' => 'NetworkConfigurationPriority'],
        'attributeType' => 'Actual',
        'attributeValue' => '1,1',
    ]]]);

    expect($response['setVariableResult'][0]['attributeStatus'] ?? null)->toBe('Accepted')
        ->and($response['setVariableResult'][1]['attributeStatus'] ?? null)->toBe('Rejected')
        ->and((new DeviceModelSettingsRepository($database))->get($chargerId, 'NetworkConfigurationPriority'))->toBe('1,0');
});

it('rejects a network priority that references a profile slot without a valid configuration', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outletSessions = new OutletSessionRepository($database);
    $outletSessions->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $deviceModel = new DeviceModel(
        $outletSessions,
        new DeviceModelSettingsRepository($database),
        $chargerId,
        new AvailabilityRepository($database),
        networkProfiles: new NetworkProfileRepository($database),
    );

    $response = $deviceModel->processVariableAction('SetVariables', ['setVariableData' => [[
        'component' => ['name' => 'OCPPCommCtrlr'],
        'variable' => ['name' => 'NetworkConfigurationPriority'],
        'attributeType' => 'Actual',
        'attributeValue' => '2,0',
    ]]]);

    expect($response['setVariableResult'][0]['attributeStatus'] ?? null)->toBe('Rejected')
        ->and((new DeviceModelSettingsRepository($database))->get($chargerId, 'NetworkConfigurationPriority'))->toBe('0');
});

it('rejects a security profile upgrade when the required CSMS trust root is unavailable', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outletSessions = new OutletSessionRepository($database);
    $outletSessions->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $networkProfiles = new NetworkProfileRepository($database);
    $networkProfiles->set($chargerId, [
        'ocppVersion' => 'OCPP20',
        'ocppTransport' => 'JSON',
        'ocppCsmsUrl' => 'wss://secure.example/ocpp',
        'messageTimeout' => 30,
        'securityProfile' => 2,
        'ocppInterface' => 'Wired0',
    ], 1);
    $deviceModel = new DeviceModel(
        $outletSessions,
        new DeviceModelSettingsRepository($database),
        $chargerId,
        new AvailabilityRepository($database),
        networkProfiles: $networkProfiles,
        networkPriorityValidator: static fn (string $priority): bool => false,
    );

    $response = $deviceModel->processVariableAction('SetVariables', ['setVariableData' => [[
        'component' => ['name' => 'OCPPCommCtrlr'],
        'variable' => ['name' => 'NetworkConfigurationPriority'],
        'attributeType' => 'Actual',
        'attributeValue' => '1,0',
    ]]]);

    expect($response['setVariableResult'][0]['attributeStatus'] ?? null)->toBe('Rejected')
        ->and((new DeviceModelSettingsRepository($database))->get($chargerId, 'NetworkConfigurationPriority'))->toBe('0');
});

it('validates the network profile connection attempt setting', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outletSessions = new OutletSessionRepository($database);
    $outletSessions->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $deviceModel = new DeviceModel(
        $outletSessions,
        new DeviceModelSettingsRepository($database),
        $chargerId,
        new AvailabilityRepository($database),
    );

    $response = $deviceModel->processVariableAction('SetVariables', ['setVariableData' => [[
        'component' => ['name' => 'OCPPCommCtrlr'],
        'variable' => ['name' => 'NetworkProfileConnectionAttempts'],
        'attributeType' => 'Actual',
        'attributeValue' => '4',
    ], [
        'component' => ['name' => 'OCPPCommCtrlr'],
        'variable' => ['name' => 'NetworkProfileConnectionAttempts'],
        'attributeType' => 'Actual',
        'attributeValue' => '0',
    ]]]);

    expect($response['setVariableResult'][0]['attributeStatus'] ?? null)->toBe('Accepted')
        ->and($response['setVariableResult'][1]['attributeStatus'] ?? null)->toBe('Rejected')
        ->and((new DeviceModelSettingsRepository($database))->get($chargerId, 'NetworkProfileConnectionAttempts'))->toBe('4');
});

it('accepts valid BasicAuthPassword updates and rejects invalid values without exposing the password', function (): void {
    $database = Database::connect(':memory:');
    $chargers = new ChargerRepository($database);
    $chargerId = $chargers->createDefault();
    $outletSessions = new OutletSessionRepository($database);
    $outletSessions->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $deviceModel = new DeviceModel(
        $outletSessions,
        new DeviceModelSettingsRepository($database),
        $chargerId,
        new AvailabilityRepository($database),
        static fn (string $password): null => $chargers->setCsmsPassword($chargerId, $password),
    );

    $component = ['name' => 'SecurityCtrlr'];
    $variable = ['name' => 'BasicAuthPassword'];
    $setResponse = $deviceModel->processVariableAction('SetVariables', ['setVariableData' => [[
        'component' => $component,
        'variable' => $variable,
        'attributeType' => 'Actual',
        'attributeValue' => 'too-short',
    ], [
        'component' => $component,
        'variable' => $variable,
        'attributeType' => 'Actual',
        'attributeValue' => 'password has spaces',
    ], [
        'component' => $component,
        'variable' => $variable,
        'attributeType' => 'Actual',
        'attributeValue' => 'new-basic-auth-password-123456',
    ]]]);
    $getResponse = $deviceModel->processVariableAction('GetVariables', ['getVariableData' => [[
        'component' => $component,
        'variable' => $variable,
    ]]]);
    $passwordReport = array_values(array_filter(
        $deviceModel->inventory('FullInventory'),
        static fn (array $entry): bool => ($entry['component']['name'] ?? null) === 'SecurityCtrlr'
            && ($entry['variable']['name'] ?? null) === 'BasicAuthPassword',
    ));

    expect(array_column($setResponse['setVariableResult'] ?? [], 'attributeStatus'))->toBe(['Rejected', 'Rejected', 'Accepted'])
        ->and($chargers->get($chargerId)['csms_password'])->toBe('new-basic-auth-password-123456')
        ->and($getResponse['getVariableResult'][0]['attributeStatus'] ?? null)->toBe('Rejected')
        ->and(array_key_exists('attributeValue', $getResponse['getVariableResult'][0] ?? []))->toBeFalse()
        ->and(count($passwordReport))->toBe(1)
        ->and($passwordReport[0]['variableAttribute'][0]['mutability'] ?? null)->toBe('WriteOnly')
        ->and(array_key_exists('value', $passwordReport[0]['variableAttribute'][0] ?? []))->toBeFalse()
        ->and($passwordReport[0]['variableCharacteristics']['maxLimit'] ?? null)->toBe(64);
});
