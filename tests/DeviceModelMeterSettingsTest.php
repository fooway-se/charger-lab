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
        ],
    ]);

    expect(array_column($getInitial['getVariableResult'], 'attributeValue'))->toBe(['0', 'Energy.Active.Import.Register'])
        ->and(array_column($set['setVariableResult'], 'attributeStatus'))->toBe(['Accepted', 'Accepted'])
        ->and(array_column($getUpdated['getVariableResult'], 'attributeValue'))->toBe(['5', 'Energy.Active.Import.Register']);
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

    expect(array_column($set['setVariableResult'], 'attributeStatus'))->toBe(['Rejected', 'Rejected'])
        ->and(array_column($get['getVariableResult'], 'attributeValue'))->toBe(['0', 'Energy.Active.Import.Register']);
});
