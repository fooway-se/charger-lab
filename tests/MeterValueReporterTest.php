<?php

declare(strict_types=1);

use ChargerEmulator\Protocol\Connection;
use ChargerEmulator\Protocol\MeterValueReporter;
use ChargerEmulator\Protocol\OcppClient;
use ChargerEmulator\Protocol\TransactionEventDelivery;
use ChargerEmulator\Domain\MeterMeasurands;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\DeviceModelSettingsRepository;
use ChargerEmulator\Storage\OutletSessionRepository;
use ChargerEmulator\Storage\TransactionEventOutboxRepository;

final class MeterValueReporterTestConnection implements Connection
{
    public ?array $call = null;

    public function send(string $message): void
    {
        $this->call = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
    }

    public function receive(): string
    {
        return json_encode([3, $this->call[1], new stdClass()], JSON_THROW_ON_ERROR);
    }
}

it('includes a zero watt boundary sample when active import power is configured', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outletSessions = new OutletSessionRepository($database);
    $outletSessions->initialize($chargerId, [['number' => 1, 'evse_id' => 1, 'connector_id' => 1]]);
    $settings = new DeviceModelSettingsRepository($database);
    $settings->set($chargerId, 'TxStartedMeasurands', 'Energy.Active.Import.Register,Power.Active.Import');
    $settings->set($chargerId, 'TxEndedMeasurands', 'Power.Active.Import');
    $clientFactory = static fn (int $_chargerId): OcppClient => throw new RuntimeException('No OCPP exchange expected.');
    $delivery = new TransactionEventDelivery(
        new TransactionEventOutboxRepository($database),
        $outletSessions,
        $settings,
        $clientFactory,
    );
    $reporter = new MeterValueReporter($outletSessions, $settings, $delivery, $clientFactory);

    $startedValues = $reporter->beginTransactionValue($chargerId, 21000);
    $endedValues = $reporter->endTransactionValue($chargerId, 22000);

    expect($startedValues[0]['sampledValue'] ?? null)->toBe([
        [
            'value' => 21000,
            'context' => 'Transaction.Begin',
            'measurand' => MeterMeasurands::ENERGY_REGISTER,
            'unitOfMeasure' => ['unit' => 'Wh'],
        ],
        [
            'value' => 0,
            'context' => 'Transaction.Begin',
            'measurand' => MeterMeasurands::ACTIVE_IMPORT_POWER,
            'unitOfMeasure' => ['unit' => 'W'],
        ],
    ])->and($endedValues[0]['sampledValue'][0]['value'] ?? null)->toBe(0)
        ->and($endedValues[0]['sampledValue'][0]['measurand'] ?? null)->toBe(MeterMeasurands::ACTIVE_IMPORT_POWER);
});

it('persists and delivers a periodic meter sample for an active charging transaction', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outletSessions = new OutletSessionRepository($database);
    $outletSessions->initialize($chargerId, [['number' => 1, 'evse_id' => 1, 'connector_id' => 1]]);
    $outletSessions->markOccupied($chargerId, 1, 1, 'meter-transaction');
    $outletSessions->attachToken($chargerId, 1, 1, 'meter-transaction', 'METER-TOKEN', 1);
    $database->exec("UPDATE outlet_session SET meter_updated_at = '2000-01-01T00:00:00+00:00'");
    $settings = new DeviceModelSettingsRepository($database);
    $settings->set($chargerId, 'TxUpdatedInterval', '1');
    $settings->set($chargerId, 'TxUpdatedMeasurands', 'Energy.Active.Import.Register,Power.Active.Import');
    $connection = new MeterValueReporterTestConnection();
    $clientFactory = static fn (int $_chargerId): OcppClient => new OcppClient($connection);
    $delivery = new TransactionEventDelivery(
        new TransactionEventOutboxRepository($database),
        $outletSessions,
        $settings,
        $clientFactory,
    );
    $reporter = new MeterValueReporter($outletSessions, $settings, $delivery, $clientFactory);

    expect($reporter->sendPeriodic($chargerId))->toBeTrue();

    $session = $outletSessions->find($chargerId, 1, 1);
    expect($connection->call[2] ?? null)->toBe('TransactionEvent')
        ->and($connection->call[3]['triggerReason'] ?? null)->toBe('MeterValuePeriodic')
        ->and($connection->call[3]['meterValue'][0]['sampledValue'][0]['value'] ?? null)->toBe(100)
        ->and($connection->call[3]['meterValue'][0]['sampledValue'][1] ?? null)->toBe([
            'value' => 7200,
            'context' => 'Sample.Periodic',
            'measurand' => 'Power.Active.Import',
            'unitOfMeasure' => ['unit' => 'W'],
        ])
        ->and($session['meter_wh'] ?? null)->toBe(100)
        ->and($session['seq_no'] ?? null)->toBe(2);
});
