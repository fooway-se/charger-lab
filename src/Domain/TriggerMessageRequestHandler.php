<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use ChargerEmulator\Storage\AvailabilityRepository;
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

    /**
     * @var list<array{evse_id:int,connector_id:int,connector_status:string,transaction_id:?string,id_token:?string,seq_no:int,meter_wh:int,stop_reason:?string,meter_updated_at:?string,meter_ended_at:?string,meter_end_samples:list<array<string,mixed>>,remote_start_deadline:?string,remote_start_pending:bool}>
     */
    private array $transactionSessions = [];

    public function __construct(
        private readonly int $chargerId,
        private readonly OutletSessionRepository $outletSessions,
        private readonly AvailabilityRepository $availability,
        private readonly ResetRepository $resets,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status:string}
     */
    public function handle(array $payload, ?string $bootStatus = null): array
    {
        $this->statusNotifications = [];
        $this->heartbeatRequested = false;
        $this->transactionSessions = [];
        $requestedMessage = $payload['requestedMessage'] ?? null;
        if (!is_string($requestedMessage)) {
            return ['status' => 'Rejected'];
        }

        if ($requestedMessage === 'BootNotification' && $bootStatus === 'Accepted') {
            return ['status' => 'Rejected'];
        }

        if ($requestedMessage === 'Heartbeat') {
            $this->heartbeatRequested = true;

            return ['status' => 'Accepted'];
        }

        if (!in_array($requestedMessage, ['StatusNotification', 'TransactionEvent'], true)) {
            return ['status' => 'NotImplemented'];
        }

        $outlets = $this->outletSessions->allForCharger($this->chargerId);
        $evse = $payload['evse'] ?? null;
        if (array_key_exists('evse', $payload) && !is_array($evse)) {
            return ['status' => 'Rejected'];
        }

        $evseId = is_array($evse) ? ($evse['id'] ?? null) : null;
        $connectorId = is_array($evse) ? ($evse['connectorId'] ?? null) : null;
        if ($evse !== null && (!is_int($evseId) || $evseId < 1
            || ($connectorId !== null && (!is_int($connectorId) || $connectorId < 1)))) {
            return ['status' => 'Rejected'];
        }

        $outlets = array_values(array_filter($outlets, static fn (array $outlet): bool =>
            ($evseId === null || $outlet['evse_id'] === $evseId)
            && ($connectorId === null || $outlet['connector_id'] === $connectorId)
        ));
        if ($outlets === []) {
            return ['status' => 'Rejected'];
        }

        if ($requestedMessage === 'TransactionEvent') {
            $this->transactionSessions = array_values(array_filter(
                $outlets,
                static fn (array $outlet): bool => $outlet['connector_status'] === 'Occupied'
                    && is_string($outlet['transaction_id'])
                    && $outlet['transaction_id'] !== '',
            ));
            if ($this->transactionSessions === []) {
                return ['status' => 'Rejected'];
            }

            return ['status' => 'Accepted'];
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

        return ['status' => 'Accepted'];
    }

    /**
     * @return list<array{timestamp:string,connectorStatus:string,evseId:int,connectorId:int}>
     */
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

    /**
     * @return list<array{evse_id:int,connector_id:int,connector_status:string,transaction_id:?string,id_token:?string,seq_no:int,meter_wh:int,stop_reason:?string,meter_updated_at:?string,meter_ended_at:?string,meter_end_samples:list<array<string,mixed>>,remote_start_deadline:?string,remote_start_pending:bool}>
     */
    public function takeTransactionSessions(): array
    {
        $transactionSessions = $this->transactionSessions;
        $this->transactionSessions = [];

        return $transactionSessions;
    }
}
