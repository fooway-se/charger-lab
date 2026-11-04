<?php

declare(strict_types=1);

use ChargerEmulator\Domain\DeviceModel;
use ChargerEmulator\Domain\OutletMap;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\DeviceModelSettingsRepository;
use ChargerEmulator\Storage\OutletSessionRepository;

it('reads and persists supported transaction meter sampling settings', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outletSessions = new OutletSessionRepository($database);
    $outletSessions->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $settings = new DeviceModelSettingsRepository($database);
    $deviceModel = new DeviceModel($outletSessions, $settings, $chargerId);

    $getInitial = $deviceModel->handle('GetVariables', [
        'getVariableData' => [
            [
                'component' => ['name' => 'SampledDataCtrlr'],
                'variable' => ['name' => 'TxUpdatedInterval'],
            ],
            [
                'component' => ['name' => 'SampledDataCtrlr'],
                'variable' => ['name' => 'TxUpdatedMeasurands'],
            ],
            [
                'component' => ['name' => 'SampledDataCtrlr'],
                'variable' => ['name' => 'TxStartedMeasurands'],
            ],
            [
                'component' => ['name' => 'SampledDataCtrlr'],
                'variable' => ['name' => 'TxEndedMeasurands'],
            ],
            [
                'component' => ['name' => 'SampledDataCtrlr'],
                'variable' => ['name' => 'TxEndedInterval'],
            ],
        ],
    ]);
    $set = $deviceModel->handle('SetVariables', [
        'setVariableData' => [
            [
                'component' => ['name' => 'SampledDataCtrlr'],
                'variable' => ['name' => 'TxUpdatedInterval'],
                'attributeValue' => '5',
            ],
            [
                'component' => ['name' => 'SampledDataCtrlr'],
                'variable' => ['name' => 'TxUpdatedMeasurands'],
                'attributeValue' => 'Energy.Active.Import.Register',
            ],
            [
                'component' => ['name' => 'SampledDataCtrlr'],
                'variable' => ['name' => 'TxStartedMeasurands'],
                'attributeValue' => '',
            ],
            [
                'component' => ['name' => 'SampledDataCtrlr'],
                'variable' => ['name' => 'TxEndedMeasurands'],
                'attributeValue' => '',
            ],
            [
                'component' => ['name' => 'SampledDataCtrlr'],
                'variable' => ['name' => 'TxEndedInterval'],
                'attributeValue' => '5',
            ],
        ],
    ]);
    $reopenedModel = new DeviceModel(
        $outletSessions,
        new DeviceModelSettingsRepository($database),
        $chargerId,
    );
    $getUpdated = $reopenedModel->handle('GetVariables', [
        'getVariableData' => [
            [
                'component' => ['name' => 'SampledDataCtrlr'],
                'variable' => ['name' => 'TxUpdatedInterval'],
            ],
            [
                'component' => ['name' => 'SampledDataCtrlr'],
                'variable' => ['name' => 'TxUpdatedMeasurands'],
            ],
            [
                'component' => ['name' => 'SampledDataCtrlr'],
                'variable' => ['name' => 'TxStartedMeasurands'],
            ],
            [
                'component' => ['name' => 'SampledDataCtrlr'],
                'variable' => ['name' => 'TxEndedMeasurands'],
            ],
            [
                'component' => ['name' => 'SampledDataCtrlr'],
                'variable' => ['name' => 'TxEndedInterval'],
            ],
        ],
    ]);

    expect(array_column($getInitial['getVariableResult'], 'attributeValue'))->toBe(['0', 'Energy.Active.Import.Register', 'Energy.Active.Import.Register', 'Energy.Active.Import.Register', '0'])
        ->and(array_column($set['setVariableResult'], 'attributeStatus'))->toBe(['Accepted', 'Accepted', 'Accepted', 'Accepted', 'Accepted'])
        ->and(array_column($getUpdated['getVariableResult'], 'attributeValue'))->toBe(['5', 'Energy.Active.Import.Register', '', '', '5']);
});

it('rejects invalid transaction meter sampling settings without changing saved values', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outletSessions = new OutletSessionRepository($database);
    $outletSessions->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $settings = new DeviceModelSettingsRepository($database);
    $deviceModel = new DeviceModel($outletSessions, $settings, $chargerId);

    $set = $deviceModel->handle('SetVariables', [
        'setVariableData' => [
            [
                'component' => ['name' => 'SampledDataCtrlr'],
                'variable' => ['name' => 'TxUpdatedInterval'],
                'attributeValue' => '-1',
            ],
            [
                'component' => ['name' => 'SampledDataCtrlr'],
                'variable' => ['name' => 'TxUpdatedMeasurands'],
                'attributeValue' => 'MadeUp.Measurand',
            ],
            [
                'component' => ['name' => 'SampledDataCtrlr'],
                'variable' => ['name' => 'TxStartedMeasurands'],
                'attributeValue' => 'MadeUp.Measurand',
            ],
            [
                'component' => ['name' => 'SampledDataCtrlr'],
                'variable' => ['name' => 'TxEndedMeasurands'],
                'attributeValue' => 'MadeUp.Measurand',
            ],
            [
                'component' => ['name' => 'SampledDataCtrlr'],
                'variable' => ['name' => 'TxEndedInterval'],
                'attributeValue' => '-1',
            ],
        ],
    ]);
    $get = $deviceModel->handle('GetVariables', [
        'getVariableData' => [
            [
                'component' => ['name' => 'SampledDataCtrlr'],
                'variable' => ['name' => 'TxUpdatedInterval'],
            ],
            [
                'component' => ['name' => 'SampledDataCtrlr'],
                'variable' => ['name' => 'TxUpdatedMeasurands'],
            ],
        ],
    ]);

    expect(array_column($set['setVariableResult'], 'attributeStatus'))->toBe(['Rejected', 'Rejected', 'Rejected', 'Rejected', 'Rejected'])
        ->and(array_column($get['getVariableResult'], 'attributeValue'))->toBe(['0', 'Energy.Active.Import.Register']);
});

it('reads and writes transaction event retry settings on OCPPCommCtrlr', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outletSessions = new OutletSessionRepository($database);
    $outletSessions->initialize($chargerId, (new OutletMap(1, 'independent'))->outlets());
    $deviceModel = new DeviceModel(
        $outletSessions,
        new DeviceModelSettingsRepository($database),
        $chargerId,
    );

    $initial = $deviceModel->handle('GetVariables', [
        'getVariableData' => [
            [
                'component' => ['name' => 'OCPPCommCtrlr'],
                'variable' => ['name' => 'MessageAttemptsTransactionEvent'],
            ],
            [
                'component' => ['name' => 'OCPPCommCtrlr'],
                'variable' => ['name' => 'MessageAttemptIntervalTransactionEvent'],
            ],
        ],
    ]);
    $set = $deviceModel->handle('SetVariables', [
        'setVariableData' => [
            [
                'component' => ['name' => 'OCPPCommCtrlr'],
                'variable' => ['name' => 'MessageAttemptsTransactionEvent'],
                'attributeValue' => '4',
            ],
            [
                'component' => ['name' => 'OCPPCommCtrlr'],
                'variable' => ['name' => 'MessageAttemptIntervalTransactionEvent'],
                'attributeValue' => '7',
            ],
        ],
    ]);
    $updated = $deviceModel->handle('GetVariables', [
        'getVariableData' => [
            [
                'component' => ['name' => 'OCPPCommCtrlr'],
                'variable' => ['name' => 'MessageAttemptsTransactionEvent'],
            ],
            [
                'component' => ['name' => 'OCPPCommCtrlr'],
                'variable' => ['name' => 'MessageAttemptIntervalTransactionEvent'],
            ],
        ],
    ]);

    expect(array_column($initial['getVariableResult'], 'attributeValue'))->toBe(['3', '60'])
        ->and(array_column($set['setVariableResult'], 'attributeStatus'))->toBe(['Accepted', 'Accepted'])
        ->and(array_column($updated['getVariableResult'], 'attributeValue'))->toBe(['4', '7']);
});
