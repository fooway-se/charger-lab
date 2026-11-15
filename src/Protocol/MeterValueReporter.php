<?php

declare(strict_types=1);

namespace ChargerEmulator\Protocol;

use ChargerEmulator\Domain\ClockAlignedMeterValueSchedule;
use ChargerEmulator\Domain\MeterMeasurands;
use ChargerEmulator\Domain\SimulatedMeter;
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
        $measurands = $this->configuredMeasurands($chargerId, 'TxStartedMeasurands');
        if ($measurands === []) {
            return null;
        }

        return $this->value($meterWh, 'Transaction.Begin', measurands: $measurands);
    }

    public function endTransactionValue(int $chargerId, int $meterWh): ?array
    {
        $measurands = $this->configuredMeasurands($chargerId, 'TxEndedMeasurands');
        if ($measurands === []) {
            return null;
        }

        return $this->value($meterWh, 'Transaction.End', measurands: $measurands);
    }

    /**
     * @param list<string> $measurands
     * @return list<array{timestamp:string,sampledValue:list<array<string,mixed>>}>
     */
    public function value(
        int $meterWh,
        string $context,
        ?string $sampledAt = null,
        array $measurands = [MeterMeasurands::ENERGY_REGISTER],
        int $powerW = 0,
    ): array
    {
        $sampledValues = [];
        foreach ($measurands as $measurand) {
            $sampledValues[] = [
                'value' => $measurand === MeterMeasurands::ENERGY_REGISTER ? $meterWh : $powerW,
                'context' => $context,
                'measurand' => $measurand,
                'unitOfMeasure' => ['unit' => $measurand === MeterMeasurands::ENERGY_REGISTER ? 'Wh' : 'W'],
            ];
        }

        return [[
            'timestamp' => $sampledAt ?? gmdate(DATE_ATOM),
            'sampledValue' => $sampledValues,
        ]];
    }

    public function resetSchedule(int $chargerId): void
    {
        unset($this->alignedSchedules[$chargerId]);
    }

    public function sendPeriodic(int $chargerId): bool
    {
        $sampling = $this->meterValueSettings($chargerId, 'TxUpdatedInterval', 'TxUpdatedMeasurands');
        if ($sampling === null) {
            return false;
        }
        $interval = $sampling['interval'];
        $measurands = $sampling['measurands'];

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
            $powerW = SimulatedMeter::powerForOutlet($session);
            $meterWh = $session['meter_wh'] + ($powerW > 0 ? self::PERIODIC_ENERGY_WH : 0);
            $sampledAt = gmdate(DATE_ATOM);
            $event = [
                'eventType' => 'Updated',
                'timestamp' => $sampledAt,
                'triggerReason' => 'MeterValuePeriodic',
                'seqNo' => $sequence,
                'meterValue' => $this->value(
                    $meterWh,
                    'Sample.Periodic',
                    measurands: $measurands,
                    powerW: $powerW,
                ),
                'transactionInfo' => [
                    'transactionId' => $session['transaction_id'],
                    'chargingState' => $session['suspended_by'] === 'EV'
                        ? 'SuspendedEV'
                        : ($session['suspended_by'] === 'EVSE' ? 'SuspendedEVSE' : 'Charging'),
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
        $sampling = $this->meterValueSettings($chargerId, 'Interval', 'Measurands');
        if ($sampling === null) {
            unset($this->alignedSchedules[$chargerId]);

            return false;
        }
        $interval = $sampling['interval'];
        $measurands = $sampling['measurands'];

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
                $powerW = SimulatedMeter::powerForOutlet($session);
                $meterWh = $session['meter_wh'] + ($powerW > 0 ? self::PERIODIC_ENERGY_WH : 0);
                $event = [
                    'eventType' => 'Updated',
                    'timestamp' => $sampledAt,
                    'triggerReason' => 'MeterValueClock',
                    'seqNo' => $sequence,
                    'meterValue' => $this->value(
                        $meterWh,
                        'Sample.Clock',
                        $sampledAt,
                        $measurands,
                        $powerW,
                    ),
                    'transactionInfo' => [
                        'transactionId' => $session['transaction_id'],
                        'chargingState' => $session['remote_start_pending']
                            ? 'Idle'
                            : ($session['suspended_by'] === 'EV'
                                ? 'SuspendedEV'
                                : ($session['suspended_by'] === 'EVSE' ? 'SuspendedEVSE' : 'Charging')),
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
                    null,
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

            $metersByEvse[$evseId] ??= ['meter_wh' => 0, 'power_w' => 0];
            $metersByEvse[$evseId]['meter_wh'] += $outlet['meter_wh'];
            $metersByEvse[$evseId]['power_w'] += SimulatedMeter::powerForOutlet($outlet);
        }

        foreach ($metersByEvse as $evseId => $meter) {
            ($this->ocppClientFactory)($chargerId)->request('MeterValues', [
                'evseId' => (int) $evseId,
                'meterValue' => $this->value($meter['meter_wh'], 'Sample.Clock', $sampledAt, $measurands, $meter['power_w']),
            ]);
        }

        return $activeTransactionsByEvse !== [] || $metersByEvse !== [];
    }

    public function sampleTransactionEnd(int $chargerId): bool
    {
        $samplingSources = [
            [
                'interval' => 'TxEndedInterval',
                'measurands' => 'TxEndedMeasurands',
                'context' => 'Sample.Periodic',
            ],
            [
                'interval' => 'AlignedTxEndedInterval',
                'measurands' => 'AlignedTxEndedMeasurands',
                'context' => 'Sample.Clock',
            ],
        ];

        foreach ($this->outletSessions->allForCharger($chargerId) as $session) {
            if ($session['connector_status'] !== 'Occupied' || $session['transaction_id'] === null
                || $session['remote_start_pending']) {
                continue;
            }

            foreach ($samplingSources as $source) {
                $sampling = $this->meterValueSettings($chargerId, $source['interval'], $source['measurands']);
                if ($sampling === null) {
                    continue;
                }

                $lastSampleAt = $this->lastTransactionEndSampleAt($session, $source['context']);
                if ($lastSampleAt === null && is_string($session['meter_ended_at'])) {
                    $lastSampleAt = strtotime($session['meter_ended_at']) ?: null;
                }

                $now = time();
                $sampleTimestamp = $now;
                if ($source['context'] === 'Sample.Clock') {
                    $sampleTimestamp = (new ClockAlignedMeterValueSchedule())->nextSampleAt(
                        $sampling['interval'],
                        $lastSampleAt ?? ($now - $sampling['interval']),
                    ) ?? PHP_INT_MAX;
                    if ($now < $sampleTimestamp) {
                        continue;
                    }
                } elseif ($lastSampleAt !== null && $now < $lastSampleAt + $sampling['interval']) {
                    continue;
                }

                $sampledAt = $source['context'] === 'Sample.Clock'
                    ? gmdate('Y-m-d\\TH:i:s\\Z', $sampleTimestamp)
                    : gmdate(DATE_ATOM, $sampleTimestamp);
                $powerW = SimulatedMeter::powerForOutlet($session);
                $meterWh = $session['meter_wh'] + ($powerW > 0 ? self::PERIODIC_ENERGY_WH : 0);
                $meterValue = $this->value(
                    $meterWh,
                    $source['context'],
                    $sampledAt,
                    $sampling['measurands'],
                    $powerW,
                )[0];
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
        }

        return false;
    }

    private function lastTransactionEndSampleAt(array $session, string $context): ?int
    {
        $lastSampleAt = null;
        foreach ($session['meter_end_samples'] as $meterValue) {
            if (($meterValue['sampledValue'][0]['context'] ?? null) !== $context) {
                continue;
            }

            $timestamp = strtotime($meterValue['timestamp'] ?? '');
            if ($timestamp !== false && ($lastSampleAt === null || $timestamp > $lastSampleAt)) {
                $lastSampleAt = $timestamp;
            }
        }

        return $lastSampleAt;
    }

    /** @return array{interval:int,measurands:list<string>}|null */
    private function meterValueSettings(int $chargerId, string $intervalVariable, string $measurandVariable): ?array
    {
        $interval = $this->settings->getInteger($chargerId, $intervalVariable, 0, 1);
        $measurands = $this->configuredMeasurands($chargerId, $measurandVariable);
        if ($interval === 0 || $measurands === []) {
            return null;
        }

        return ['interval' => $interval, 'measurands' => $measurands];
    }

    /** @return list<string> */
    private function configuredMeasurands(int $chargerId, string $variable): array
    {
        $measurands = MeterMeasurands::parse($this->settings->get($chargerId, $variable) ?? '');

        return $measurands ?? [];
    }

}
