<?php

declare(strict_types=1);

use ChargerEmulator\Domain\BaseReportRequestHandler;
use ChargerEmulator\Domain\DeviceModel;
use ChargerEmulator\Domain\OutletMap;
use ChargerEmulator\Storage\AvailabilityRepository;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\DeviceModelSettingsRepository;
use ChargerEmulator\Storage\OutletSessionRepository;

function createBaseReportHandler(): BaseReportRequestHandler
{
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outletSessions = new OutletSessionRepository($database);
    $outletSessions->initialize($chargerId, (new OutletMap(2, 'shared'))->outlets());

    return new BaseReportRequestHandler(new DeviceModel(
        $outletSessions,
        new DeviceModelSettingsRepository($database),
        $chargerId,
        new AvailabilityRepository($database),
    ));
}

it('sends a full device inventory after accepting GetBaseReport', function (): void {
    $handler = createBaseReportHandler();

    expect($handler->getBaseReport(['requestId' => 14, 'reportBase' => 'FullInventory']))
        ->toBe(['status' => 'Accepted']);

    $report = $handler->takeReport();
    expect($report['requestId'] ?? null)->toBe(14)
        ->and($report['seqNo'] ?? null)->toBe(0)
        ->and($report['tbc'] ?? null)->toBeFalse()
        ->and($report['reportData'] ?? null)->toBeArray()
        ->and(array_column($report['reportData'], 'variableCharacteristics'))->not->toBeEmpty();
});

it('reports controller variables for ConfigurationInventory', function (): void {
    $handler = createBaseReportHandler();

    expect($handler->getBaseReport(['requestId' => 15, 'reportBase' => 'ConfigurationInventory']))
        ->toBe(['status' => 'Accepted']);

    $entries = $handler->takeReport()['reportData'] ?? [];
    expect($entries)->not->toBeEmpty();
    foreach ($entries as $entry) {
        expect($entry['component']['name'] ?? null)->toBeIn(['AlignedDataCtrlr', 'AuthCtrlr', 'OCPPCommCtrlr', 'SampledDataCtrlr', 'TxCtrlr'])
            ->and($entry['variableAttribute'])->toHaveCount(1);
    }
    expect(array_column(array_column($entries, 'variable'), 'name'))->not->toContain('AuthorizeRemoteStart');
    $retrySetting = array_values(array_filter($entries, static fn (array $entry): bool => ($entry['variable']['name'] ?? null) === 'MessageAttempts'))[0] ?? [];
    expect($retrySetting['variable']['instance'] ?? null)->toBe('TransactionEvent');
    $networkPriority = array_values(array_filter($entries, static fn (array $entry): bool => ($entry['variable']['name'] ?? null) === 'NetworkConfigurationPriority'))[0] ?? [];
    expect($networkPriority['variableAttribute'][0]['value'] ?? null)->toBe('0')
        ->and($networkPriority['variableCharacteristics']['valuesList'] ?? null)->toBe('0,1,2');
});

it('reports current outlet availability for SummaryInventory', function (): void {
    $handler = createBaseReportHandler();

    expect($handler->getBaseReport(['requestId' => 16, 'reportBase' => 'SummaryInventory']))
        ->toBe(['status' => 'Accepted']);

    $entries = $handler->takeReport()['reportData'] ?? [];
    expect(array_column(array_column($entries, 'component'), 'name'))
        ->toContain('ChargingStation', 'EVSE', 'Connector');
    foreach ($entries as $entry) {
        expect($entry['variable']['name'] ?? null)->toBe('AvailabilityState')
            ->and($entry['variableCharacteristics']['dataType'] ?? null)->toBe('OptionList')
            ->and($entry['variableCharacteristics']['valuesList'] ?? null)->toBe('Available,Occupied,Unavailable');
    }
});
