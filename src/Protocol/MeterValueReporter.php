<?php

declare(strict_types=1);

namespace ChargerEmulator\Protocol;

use ChargerEmulator\Domain\ClockAlignedMeterValueSchedule;
use ChargerEmulator\Storage\DeviceModelSettingsRepository;
use ChargerEmulator\Storage\OutletSessionRepository;
use Closure;

/**
 * Schedules and reports transaction and clock-aligned meter samples for a charger.
 */
final class MeterValueReporter
{
    private const PERIODIC_ENERGY_WH = 100;

    /**
     * @var array<int, array{interval:int,next_at:int}>
     */
    private array $alignedSchedules = [];

    /**
     * @var Closure(int): OcppClient
     */
    private readonly Closure $ocppClientFactory;

    public function __construct(
        private readonly OutletSessionRepository $outletSessions,
        private readonly DeviceModelSettingsRepository $settings,
        private readonly TransactionEventDelivery $transactionEvents,
        Closure $ocppClientFactory,
    ) {
        $this->ocppClientFactory = $ocppClientFactory;
    }

    public function beginTransactionValue(int $chargerId, int $meterWh): ?array
    {
        if ($this->settings->get($chargerId, 'TxStartedMeasurands') !== 'Energy.Active.Import.Register') {
            return null;
        }

        return $this->value($meterWh, 'Transaction.Begin');
    }

    public function endTransactionValue(int $chargerId, int $meterWh): ?array
    {
        if ($this->settings->get($chargerId, 'TxEndedMeasurands') !== 'Energy.Active.Import.Register') {
            return null;
        }

        return $this->value($meterWh, 'Transaction.End');
    }

    public function value(int $meterWh, string $context, ?string $sampledAt = null): array
    {
        return [[
            'timestamp' => $sampledAt ?? gmdate(DATE_ATOM),
            'sampledValue' => [[
                'value' => $meterWh,
                'context' => $context,
                'measurand' => 'Energy.Active.Import.Register',
                'unitOfMeasure' => ['unit' => 'Wh'],
            ]],
        ]];
    }

    public function resetSchedule(int $chargerId): void
    {
        unset($this->alignedSchedules[$chargerId]);
    }

    public function sendPeriodic(int $chargerId): bool
    {
        $interval = $this->meterValueInterval($chargerId, 'TxUpdatedInterval', 'TxUpdatedMeasurands');
        if ($interval === null) {
            return false;
        }

        foreach ($this->outletSessions->allForCharger($chargerId) as $session) {
            if ($session['connector_status'] !== 'Occupied' || $session['transaction_id'] === null
                || $session['id_token'] === null || $session['stop_reason'] !== null
                || $session['remote_start_pending']) {
                continue;
            }

            $lastSampleAt = $session['meter_updated_at'] === null
                ? false
                : strtotime($session['meter_updated_at']);
            if ($lastSampleAt !== false && time() < $lastSampleAt + $interval) {
                continue;
            }

            $sequence = $session['seq_no'] + 1;
            $meterWh = $session['meter_wh'] + self::PERIODIC_ENERGY_WH;
            $sampledAt = gmdate(DATE_ATOM);
            $event = [
                'eventType' => 'Updated',
                'timestamp' => $sampledAt,
                'triggerReason' => 'MeterValuePeriodic',
                'seqNo' => $sequence,
                'meterValue' => $this->value($meterWh, 'Sample.Periodic'),
                'transactionInfo' => [
                    'transactionId' => $session['transaction_id'],
                    'chargingState' => 'Charging',
                ],
                'evse' => ['id' => $session['evse_id'], 'connectorId' => $session['connector_id']],
            ];
            $queuedEvent = $this->transactionEvents->enqueue($chargerId, $event);
            $this->outletSessions->recordMeterSample(
                $chargerId,
                $session['evse_id'],
                $session['connector_id'],
                $session['transaction_id'],
                $meterWh,
                $sequence,
                $sampledAt,
            );
            $this->transactionEvents->deliverThrough($chargerId, $queuedEvent['id']);

            return true;
        }

        return false;
    }

    public function sendClockAligned(int $chargerId): bool
    {
        $interval = $this->meterValueInterval($chargerId, 'Interval', 'Measurands');
        if ($interval === null) {
            unset($this->alignedSchedules[$chargerId]);

            return false;
        }

        $now = time();
        $schedule = new ClockAlignedMeterValueSchedule();
        if (!isset($this->alignedSchedules[$chargerId])
            || $this->alignedSchedules[$chargerId]['interval'] !== $interval) {
            $this->alignedSchedules[$chargerId] = [
                'interval' => $interval,
                'next_at' => $schedule->nextSampleAt($interval, $now) ?? PHP_INT_MAX,
            ];

            return false;
        }

        $sampleTimestamp = $this->alignedSchedules[$chargerId]['next_at'];
        if ($now < $sampleTimestamp) {
            return false;
        }

        $this->alignedSchedules[$chargerId]['next_at'] = $schedule->nextSampleAt($interval, $sampleTimestamp) ?? PHP_INT_MAX;
        $sampledAt = gmdate('Y-m-d\TH:i:s\Z', $sampleTimestamp);
        $outlets = $this->outletSessions->allForCharger($chargerId);
        $activeTransactionsByEvse = [];
        foreach ($outlets as $outlet) {
            if ($outlet['connector_status'] !== 'Occupied' || !is_string($outlet['transaction_id'])
                || $outlet['transaction_id'] === '') {
                continue;
            }

            $activeTransactionsByEvse[$outlet['evse_id']][] = $outlet;
        }

        $sendDuringIdle = $this->settings->get($chargerId, 'SendDuringIdle') === 'true';
        foreach ($activeTransactionsByEvse as $transactions) {
            if ($sendDuringIdle) {
                continue;
            }

            foreach ($transactions as $session) {
                $sequence = $session['seq_no'] + 1;
                $meterWh = $session['meter_wh'] + self::PERIODIC_ENERGY_WH;
                $event = [
                    'eventType' => 'Updated',
                    'timestamp' => $sampledAt,
                    'triggerReason' => 'MeterValueClock',
                    'seqNo' => $sequence,
                    'meterValue' => $this->value($meterWh, 'Sample.Clock', $sampledAt),
                    'transactionInfo' => [
                        'transactionId' => $session['transaction_id'],
                        'chargingState' => $session['remote_start_pending'] ? 'Idle' : 'Charging',
                    ],
                    'evse' => ['id' => $session['evse_id'], 'connectorId' => $session['connector_id']],
                ];
                $queuedEvent = $this->transactionEvents->enqueue($chargerId, $event);
                $this->outletSessions->recordMeterSample(
                    $chargerId,
                    $session['evse_id'],
                    $session['connector_id'],
                    $session['transaction_id'],
                    $meterWh,
                    $sequence,
                    $sampledAt,
                );
                $this->transactionEvents->deliverThrough($chargerId, $queuedEvent['id']);
            }
        }

        $metersByEvse = [];
        foreach ($outlets as $outlet) {
            $evseId = $outlet['evse_id'];
            if (isset($activeTransactionsByEvse[$evseId])) {
                continue;
            }

            $metersByEvse[$evseId] = ($metersByEvse[$evseId] ?? 0) + $outlet['meter_wh'];
        }

        foreach ($metersByEvse as $evseId => $meterWh) {
            ($this->ocppClientFactory)($chargerId)->request('MeterValues', [
                'evseId' => (int) $evseId,
                'meterValue' => $this->value($meterWh, 'Sample.Clock', $sampledAt),
            ]);
        }

        return $activeTransactionsByEvse !== [] || $metersByEvse !== [];
    }

    public function sampleTransactionEnd(int $chargerId): bool
    {
        $interval = $this->meterValueInterval($chargerId, 'TxEndedInterval', 'TxEndedMeasurands');
        if ($interval === null) {
            return false;
        }

        foreach ($this->outletSessions->allForCharger($chargerId) as $session) {
            if ($session['connector_status'] !== 'Occupied' || $session['transaction_id'] === null
                || $session['remote_start_pending']) {
                continue;
            }

            $lastSampleAt = $session['meter_ended_at'] === null
                ? false
                : strtotime($session['meter_ended_at']);
            if ($lastSampleAt !== false && time() < $lastSampleAt + $interval) {
                continue;
            }

            $sampledAt = gmdate(DATE_ATOM);
            $meterWh = $session['meter_wh'] + self::PERIODIC_ENERGY_WH;
            $meterValue = $this->value($meterWh, 'Sample.Periodic', $sampledAt)[0];
            $this->outletSessions->recordTransactionEndSample(
                $chargerId,
                $session['evse_id'],
                $session['connector_id'],
                $session['transaction_id'],
                $meterWh,
                $sampledAt,
                $meterValue,
            );

            return true;
        }

        return false;
    }

    private function meterValueInterval(int $chargerId, string $intervalVariable, string $measurandVariable): ?int
    {
        $interval = $this->settings->getInteger($chargerId, $intervalVariable, 0, 1);
        if ($interval === 0 || $this->settings->get($chargerId, $measurandVariable) !== 'Energy.Active.Import.Register') {
            return null;
        }

        return $interval;
    }

}
