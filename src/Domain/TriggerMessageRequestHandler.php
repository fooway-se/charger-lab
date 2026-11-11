<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use ChargerEmulator\Storage\AvailabilityRepository;
use ChargerEmulator\Storage\DeviceModelSettingsRepository;
use ChargerEmulator\Storage\OutletSessionRepository;
use ChargerEmulator\Storage\ResetRepository;

/**
 * Resolves supported CSMS TriggerMessage requests to current station data.
 */
final class TriggerMessageRequestHandler
{
    /**
     * @var list<array{timestamp:string,connectorStatus:string,evseId:int,connectorId:int}>
     */
    private array $statusNotifications = [];

    private bool $heartbeatRequested = false;

    private bool $firmwareStatusNotificationRequested = false;

    /**
     * @var list<array{evse_id:int,connector_id:int,connector_status:string,transaction_id:?string,id_token:?string,seq_no:int,meter_wh:int,stop_reason:?string,meter_updated_at:?string,meter_ended_at:?string,meter_end_samples:list<array<string,mixed>>,remote_start_deadline:?string,remote_start_pending:bool}>
     */
    private array $transactionSessions = [];

    /**
     * @var list<array{evse_id:int,meter_wh:int,power_w:int}>
     */
    private array $meterValueRequests = [];

    public function __construct(
        private readonly int $chargerId,
        private readonly OutletSessionRepository $outletSessions,
        private readonly AvailabilityRepository $availability,
        private readonly ResetRepository $resets,
        private readonly DeviceModelSettingsRepository $settings,
    ) {
    }

    public function triggerMessage(array $payload, ?string $bootStatus = null): array
    {
        $this->statusNotifications = [];
        $this->heartbeatRequested = false;
        $this->firmwareStatusNotificationRequested = false;
        $this->transactionSessions = [];
        $this->meterValueRequests = [];
        $requestedMessage = $payload['requestedMessage'] ?? null;
        if (!is_string($requestedMessage)) {
            return $this->statusResult('Rejected');
        }

        if ($requestedMessage === 'BootNotification' && $bootStatus === 'Accepted') {
            return $this->statusResult('Rejected');
        }

        if ($requestedMessage === 'Heartbeat') {
            $this->heartbeatRequested = true;

            return $this->statusResult('Accepted');
        }

        if ($requestedMessage === 'FirmwareStatusNotification') {
            $this->firmwareStatusNotificationRequested = true;

            return $this->statusResult('Accepted');
        }

        if (!in_array($requestedMessage, ['StatusNotification', 'TransactionEvent', 'MeterValues'], true)) {
            return $this->statusResult('NotImplemented');
        }

        $outlets = $this->outletSessions->allForCharger($this->chargerId);
        $evse = $payload['evse'] ?? null;
        if (array_key_exists('evse', $payload) && !is_array($evse)) {
            return $this->statusResult('Rejected');
        }

        $evseId = is_array($evse) ? ($evse['id'] ?? null) : null;
        $connectorId = is_array($evse) ? ($evse['connectorId'] ?? null) : null;
        if ($evse !== null && (!is_int($evseId) || $evseId < 1
            || ($connectorId !== null && (!is_int($connectorId) || $connectorId < 1)))) {
            return $this->statusResult('Rejected');
        }

        $outlets = array_values(array_filter($outlets, static fn (array $outlet): bool =>
            ($evseId === null || $outlet['evse_id'] === $evseId)
            && ($requestedMessage === 'MeterValues' || $connectorId === null || $outlet['connector_id'] === $connectorId)
        ));
        if ($outlets === []) {
            return $this->statusResult('Rejected');
        }

        if ($requestedMessage === 'TransactionEvent') {
            $this->transactionSessions = array_values(array_filter(
                $outlets,
                static fn (array $outlet): bool => $outlet['connector_status'] === 'Occupied'
                    && is_string($outlet['transaction_id'])
                    && $outlet['transaction_id'] !== '',
            ));
            if ($this->transactionSessions === []) {
                return $this->statusResult('Rejected');
            }

            return $this->statusResult('Accepted');
        }

        if ($requestedMessage === 'MeterValues') {
            $measurands = MeterMeasurands::parse($this->settings->get($this->chargerId, 'Measurands') ?? '');
            if ($measurands === null || $measurands === []) {
                return $this->statusResult('Rejected');
            }

            $metersByEvse = [];
            foreach ($outlets as $outlet) {
                $evseId = $outlet['evse_id'];
                $metersByEvse[$evseId] ??= ['meter_wh' => 0, 'power_w' => 0];
                $metersByEvse[$evseId]['meter_wh'] += $outlet['meter_wh'];
                $metersByEvse[$evseId]['power_w'] += SimulatedMeter::powerForOutlet($outlet);
            }
            foreach ($metersByEvse as $meterEvseId => $meter) {
                $this->meterValueRequests[] = [
                    'evse_id' => (int) $meterEvseId,
                    'meter_wh' => $meter['meter_wh'],
                    'power_w' => $meter['power_w'],
                ];
            }

            return $this->statusResult('Accepted');
        }

        $pendingReset = $this->resets->pending($this->chargerId);
        foreach ($outlets as $outlet) {
            $connectorStatus = $outlet['connector_status'];
            if ($pendingReset !== null && $pendingReset['type'] === 'OnIdle' && $connectorStatus === 'Available') {
                $connectorStatus = 'Unavailable';
            }

            $this->statusNotifications[] = [
                'timestamp' => gmdate(DATE_ATOM),
                'connectorStatus' => $this->availability->connectorStatus(
                    $this->chargerId,
                    $outlet['evse_id'],
                    $outlet['connector_id'],
                    $connectorStatus,
                ),
                'evseId' => $outlet['evse_id'],
                'connectorId' => $outlet['connector_id'],
            ];
        }

        return $this->statusResult('Accepted');
    }

    public function takeStatusNotifications(): array
    {
        $statusNotifications = $this->statusNotifications;
        $this->statusNotifications = [];

        return $statusNotifications;
    }

    public function takeHeartbeatRequest(): bool
    {
        $heartbeatRequested = $this->heartbeatRequested;
        $this->heartbeatRequested = false;

        return $heartbeatRequested;
    }

    public function takeFirmwareStatusNotificationRequest(): bool
    {
        $requested = $this->firmwareStatusNotificationRequested;
        $this->firmwareStatusNotificationRequested = false;

        return $requested;
    }

    public function takeTransactionSessions(): array
    {
        $transactionSessions = $this->transactionSessions;
        $this->transactionSessions = [];

        return $transactionSessions;
    }

    public function takeMeterValueRequests(): array
    {
        $meterValueRequests = $this->meterValueRequests;
        $this->meterValueRequests = [];

        return $meterValueRequests;
    }

    private function statusResult(string $status): array
    {
        return ['status' => $status];
    }
}
