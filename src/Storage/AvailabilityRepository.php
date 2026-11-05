<?php

declare(strict_types=1);

namespace ChargerEmulator\Storage;

use PDO;
use Throwable;

/**
 * Persists station, EVSE, and connector operational availability.
 */
final class AvailabilityRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status:string,changes:list<array{evse_id:int,connector_id:int,status:string}>}
     */
    public function requestChange(int $chargerId, array $payload): array
    {
        $operationalStatus = $payload['operationalStatus'] ?? null;
        $evse = array_key_exists('evse', $payload) ? $payload['evse'] : null;
        $evseId = is_array($evse) ? ($evse['id'] ?? null) : null;
        $connectorId = is_array($evse) ? ($evse['connectorId'] ?? null) : null;
        if (!is_string($operationalStatus) || !in_array($operationalStatus, ['Operative', 'Inoperative'], true)
            || (array_key_exists('evse', $payload) && !is_array($evse))
            || ($evse !== null && (!is_array($evse) || !is_int($evseId) || $evseId < 1
                || ($connectorId !== null && (!is_int($connectorId) || $connectorId < 1))))) {
            return ['status' => 'Rejected', 'changes' => []];
        }

        $scope = $evse === null ? 'station' : ($connectorId === null ? 'evse' : 'connector');
        $evseId = is_int($evseId) ? $evseId : 0;
        $connectorId = is_int($connectorId) ? $connectorId : 0;
        $outlets = $this->matchingOutlets($chargerId, $scope, $evseId, $connectorId);
        if ($outlets === []) {
            return ['status' => 'Rejected', 'changes' => []];
        }

        $before = $this->statusSnapshot($chargerId, $outlets);
        $busy = array_any($outlets, static fn (array $outlet): bool => $outlet['connector_status'] === 'Occupied');
        $existingStatus = $this->operationalStatus($chargerId, $scope, $evseId, $connectorId);
        if ($busy && $existingStatus !== $operationalStatus) {
            $this->saveStatus($chargerId, $scope, $evseId, $connectorId, $existingStatus, $operationalStatus);
            return ['status' => 'Scheduled', 'changes' => []];
        }

        $this->saveStatus($chargerId, $scope, $evseId, $connectorId, $operationalStatus, null);

        return [
            'status' => 'Accepted',
            'changes' => $this->statusChanges($chargerId, $outlets, $before),
        ];
    }

    public function connectorStatus(int $chargerId, int $evseId, int $connectorId, string $connectorStatus): string
    {
        foreach ([
            ['station', 0, 0],
            ['evse', $evseId, 0],
            ['connector', $evseId, $connectorId],
        ] as [$scope, $scopeEvseId, $scopeConnectorId]) {
            if ($this->operationalStatus($chargerId, $scope, $scopeEvseId, $scopeConnectorId) === 'Inoperative') {
                return 'Unavailable';
            }
        }

        return $connectorStatus;
    }

    public function canStartTransaction(int $chargerId, int $evseId, int $connectorId): bool
    {
        foreach ([
            ['station', 0, 0],
            ['evse', $evseId, 0],
            ['connector', $evseId, $connectorId],
        ] as [$scope, $scopeEvseId, $scopeConnectorId]) {
            $statement = $this->database->prepare(<<<'SQL'
                SELECT operational_status, pending_status FROM availability_state
                WHERE charger_id = :charger_id AND scope = :scope AND evse_id = :evse_id AND connector_id = :connector_id
                SQL);
            $statement->execute([
                'charger_id' => $chargerId,
                'scope' => $scope,
                'evse_id' => $scopeEvseId,
                'connector_id' => $scopeConnectorId,
            ]);
            $state = $statement->fetch();
            if ($state !== false
                && ($state['operational_status'] !== 'Operative' || $state['pending_status'] === 'Inoperative')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<array{evse_id:int,connector_id:int,status:string}>
     */
    public function applyPendingChanges(int $chargerId): array
    {
        $this->database->beginTransaction();

        try {
            $outlets = $this->allOutlets($chargerId);
            $before = $this->statusSnapshot($chargerId, $outlets);
            $statement = $this->database->prepare(<<<'SQL'
                SELECT scope, evse_id, connector_id, pending_status
                FROM availability_state
                WHERE charger_id = :charger_id AND pending_status IS NOT NULL
                ORDER BY CASE scope WHEN 'station' THEN 0 WHEN 'evse' THEN 1 ELSE 2 END
                SQL);
            $statement->execute(['charger_id' => $chargerId]);

            foreach ($statement->fetchAll() as $pending) {
                $targetOutlets = $this->matchingOutlets(
                    $chargerId,
                    $pending['scope'],
                    (int) $pending['evse_id'],
                    (int) $pending['connector_id'],
                );
                if ($targetOutlets === [] || array_any(
                    $targetOutlets,
                    static fn (array $outlet): bool => $outlet['connector_status'] === 'Occupied',
                )) {
                    continue;
                }

                $this->saveStatus(
                    $chargerId,
                    $pending['scope'],
                    (int) $pending['evse_id'],
                    (int) $pending['connector_id'],
                    $pending['pending_status'],
                    null,
                );
            }

            $changes = $this->statusChanges($chargerId, $outlets, $before);
            $this->database->commit();

            return $changes;
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $outlet
     * @return array{evse_id:int,connector_id:int,connector_status:string}
     */
    private function normalizeOutlet(array $outlet): array
    {
        return [
            'evse_id' => (int) $outlet['evse_id'],
            'connector_id' => (int) $outlet['connector_id'],
            'connector_status' => (string) $outlet['connector_status'],
        ];
    }

    /**
     * @return list<array{evse_id:int,connector_id:int,connector_status:string}>
     */
    private function matchingOutlets(int $chargerId, string $scope, int $evseId, int $connectorId): array
    {
        $sql = match ($scope) {
            'station' => 'SELECT evse_id, connector_id, connector_status FROM outlet_session WHERE charger_id = :charger_id',
            'evse' => 'SELECT evse_id, connector_id, connector_status FROM outlet_session WHERE charger_id = :charger_id AND evse_id = :evse_id',
            default => 'SELECT evse_id, connector_id, connector_status FROM outlet_session WHERE charger_id = :charger_id AND evse_id = :evse_id AND connector_id = :connector_id',
        };
        $statement = $this->database->prepare($sql);
        $statement->bindValue(':charger_id', $chargerId, PDO::PARAM_INT);
        if ($scope !== 'station') {
            $statement->bindValue(':evse_id', $evseId, PDO::PARAM_INT);
        }

        if ($scope === 'connector') {
            $statement->bindValue(':connector_id', $connectorId, PDO::PARAM_INT);
        }

        $statement->execute();

        return array_map(fn (array $outlet): array => $this->normalizeOutlet($outlet), $statement->fetchAll());
    }

    /**
     * @return list<array{evse_id:int,connector_id:int,connector_status:string}>
     */
    private function allOutlets(int $chargerId): array
    {
        $statement = $this->database->prepare('SELECT evse_id, connector_id, connector_status FROM outlet_session WHERE charger_id = :charger_id');
        $statement->execute(['charger_id' => $chargerId]);

        return array_map(fn (array $outlet): array => $this->normalizeOutlet($outlet), $statement->fetchAll());
    }

    /**
     * @param list<array{evse_id:int,connector_id:int,connector_status:string}> $outlets
     * @return array<string, string>
     */
    private function statusSnapshot(int $chargerId, array $outlets): array
    {
        $statuses = [];
        foreach ($outlets as $outlet) {
            $key = $outlet['evse_id'] . ':' . $outlet['connector_id'];
            $statuses[$key] = $this->connectorStatus(
                $chargerId,
                $outlet['evse_id'],
                $outlet['connector_id'],
                $outlet['connector_status'],
            );
        }

        return $statuses;
    }

    /**
     * @param list<array{evse_id:int,connector_id:int,connector_status:string}> $outlets
     * @param array<string, string> $before
     * @return list<array{evse_id:int,connector_id:int,status:string}>
     */
    private function statusChanges(int $chargerId, array $outlets, array $before): array
    {
        $changes = [];
        foreach ($outlets as $outlet) {
            $key = $outlet['evse_id'] . ':' . $outlet['connector_id'];
            $status = $this->connectorStatus(
                $chargerId,
                $outlet['evse_id'],
                $outlet['connector_id'],
                $outlet['connector_status'],
            );
            if (($before[$key] ?? null) !== $status) {
                $changes[] = [
                    'evse_id' => $outlet['evse_id'],
                    'connector_id' => $outlet['connector_id'],
                    'status' => $status,
                ];
            }
        }

        return $changes;
    }

    private function operationalStatus(int $chargerId, string $scope, int $evseId, int $connectorId): string
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT operational_status FROM availability_state
            WHERE charger_id = :charger_id AND scope = :scope AND evse_id = :evse_id AND connector_id = :connector_id
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'scope' => $scope,
            'evse_id' => $evseId,
            'connector_id' => $connectorId,
        ]);
        $status = $statement->fetchColumn();

        return is_string($status) ? $status : 'Operative';
    }

    private function saveStatus(
        int $chargerId,
        string $scope,
        int $evseId,
        int $connectorId,
        string $operationalStatus,
        ?string $pendingStatus,
    ): void {
        $statement = $this->database->prepare(<<<'SQL'
            INSERT INTO availability_state (
                charger_id, scope, evse_id, connector_id, operational_status, pending_status
            ) VALUES (
                :charger_id, :scope, :evse_id, :connector_id, :operational_status, :pending_status
            )
            ON CONFLICT (charger_id, scope, evse_id, connector_id) DO UPDATE SET
                operational_status = excluded.operational_status,
                pending_status = excluded.pending_status
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'scope' => $scope,
            'evse_id' => $evseId,
            'connector_id' => $connectorId,
            'operational_status' => $operationalStatus,
            'pending_status' => $pendingStatus,
        ]);
    }
}
