<?php

declare(strict_types=1);

namespace ChargerEmulator\Storage;

use PDO;
use JsonException;
use RuntimeException;

/**
 * Persists simulated plug and transaction state for each charger outlet.
 */
final class OutletSessionRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    /**
     * @param list<array{number:int,evse_id:int,connector_id:int}> $outlets
     */
    public function initialize(int $chargerId, array $outlets): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            INSERT OR IGNORE INTO outlet_session (charger_id, evse_id, connector_id, connector_status, seq_no)
            VALUES (:charger_id, :evse_id, :connector_id, 'Available', 0)
            SQL);
        foreach ($outlets as $outlet) {
            $statement->execute([
                'charger_id' => $chargerId,
                'evse_id' => $outlet['evse_id'],
                'connector_id' => $outlet['connector_id'],
            ]);
        }
    }

    /**
     * @return list<array{evse_id:int,connector_id:int,connector_status:string,transaction_id:?string,id_token:?string,seq_no:int,meter_wh:int,stop_reason:?string,meter_updated_at:?string,meter_ended_at:?string,meter_end_samples:list<array<string,mixed>>}>
     */
    public function allForCharger(int $chargerId): array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT evse_id, connector_id, connector_status, transaction_id, id_token, seq_no, meter_wh, stop_reason, meter_updated_at, meter_ended_at, meter_end_samples
            FROM outlet_session WHERE charger_id = :charger_id ORDER BY evse_id, connector_id
            SQL);
        $statement->execute(['charger_id' => $chargerId]);

        return array_map(static function (array $row): array {
            return [
                'evse_id' => (int) $row['evse_id'],
                'connector_id' => (int) $row['connector_id'],
                'connector_status' => $row['connector_status'],
                'transaction_id' => $row['transaction_id'],
                'id_token' => $row['id_token'],
                'seq_no' => (int) $row['seq_no'],
                'meter_wh' => (int) $row['meter_wh'],
                'stop_reason' => $row['stop_reason'],
                ...self::meterSampleFields($row),
            ];
        }, $statement->fetchAll());
    }

    /**
     * @return array{evse_id:int,connector_id:int,connector_status:string,transaction_id:?string,id_token:?string,seq_no:int,meter_wh:int,stop_reason:?string,meter_updated_at:?string,meter_ended_at:?string,meter_end_samples:list<array<string,mixed>>}|null
     */
    public function find(int $chargerId, int $evseId, int $connectorId): ?array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT evse_id, connector_id, connector_status, transaction_id, id_token, seq_no, meter_wh, stop_reason, meter_updated_at, meter_ended_at, meter_end_samples
            FROM outlet_session WHERE charger_id = :charger_id AND evse_id = :evse_id AND connector_id = :connector_id
            SQL);
        $statement->execute(['charger_id' => $chargerId, 'evse_id' => $evseId, 'connector_id' => $connectorId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'evse_id' => (int) $row['evse_id'],
            'connector_id' => (int) $row['connector_id'],
            'connector_status' => $row['connector_status'],
            'transaction_id' => $row['transaction_id'],
            'id_token' => $row['id_token'],
            'seq_no' => (int) $row['seq_no'],
            'meter_wh' => (int) $row['meter_wh'],
            'stop_reason' => $row['stop_reason'],
            ...self::meterSampleFields($row),
        ];
    }

    /**
     * @return array{evse_id:int,connector_id:int,connector_status:string,transaction_id:?string,id_token:?string,seq_no:int,meter_wh:int,stop_reason:?string,meter_updated_at:?string,meter_ended_at:?string,meter_end_samples:list<array<string,mixed>>}|null
     */
    public function findByTransactionId(int $chargerId, string $transactionId): ?array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT evse_id, connector_id, connector_status, transaction_id, id_token, seq_no, meter_wh, stop_reason, meter_updated_at, meter_ended_at, meter_end_samples
            FROM outlet_session
            WHERE charger_id = :charger_id AND transaction_id = :transaction_id AND connector_status = 'Occupied'
            LIMIT 1
            SQL);
        $statement->execute(['charger_id' => $chargerId, 'transaction_id' => $transactionId]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }

        return [
            'evse_id' => (int) $row['evse_id'],
            'connector_id' => (int) $row['connector_id'],
            'connector_status' => $row['connector_status'],
            'transaction_id' => $row['transaction_id'],
            'id_token' => $row['id_token'],
            'seq_no' => (int) $row['seq_no'],
            'meter_wh' => (int) $row['meter_wh'],
            'stop_reason' => $row['stop_reason'],
            ...self::meterSampleFields($row),
        ];
    }

    public function markOccupied(int $chargerId, int $evseId, int $connectorId, string $transactionId): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE outlet_session
            SET connector_status = 'Occupied', transaction_id = :transaction_id, id_token = NULL, seq_no = 0,
                stop_reason = NULL, meter_updated_at = :meter_updated_at, meter_ended_at = :meter_ended_at, meter_end_samples = '[]'
            WHERE charger_id = :charger_id AND evse_id = :evse_id AND connector_id = :connector_id
                AND connector_status = 'Available'
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'evse_id' => $evseId,
            'connector_id' => $connectorId,
            'transaction_id' => $transactionId,
            'meter_updated_at' => gmdate(DATE_ATOM),
            'meter_ended_at' => gmdate(DATE_ATOM),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The selected outlet is not available.');
        }
    }

    public function attachToken(int $chargerId, int $evseId, int $connectorId, string $transactionId, string $idToken, int $sequence): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE outlet_session SET id_token = :id_token, seq_no = :seq_no, meter_updated_at = :meter_updated_at
            WHERE charger_id = :charger_id AND evse_id = :evse_id AND connector_id = :connector_id
                AND connector_status = 'Occupied' AND transaction_id = :transaction_id
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'evse_id' => $evseId,
            'connector_id' => $connectorId,
            'transaction_id' => $transactionId,
            'id_token' => $idToken,
            'seq_no' => $sequence,
            'meter_updated_at' => gmdate(DATE_ATOM),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The active outlet transaction changed before authorization completed.');
        }
    }

    public function markAvailable(int $chargerId, int $evseId, int $connectorId, string $transactionId, int $meterWh): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE outlet_session
            SET connector_status = 'Available', transaction_id = NULL, id_token = NULL, seq_no = 0,
                meter_wh = :meter_wh, stop_reason = NULL, meter_updated_at = NULL, meter_ended_at = NULL, meter_end_samples = '[]'
            WHERE charger_id = :charger_id AND evse_id = :evse_id AND connector_id = :connector_id
                AND transaction_id = :transaction_id
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'evse_id' => $evseId,
            'connector_id' => $connectorId,
            'transaction_id' => $transactionId,
            'meter_wh' => $meterWh,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The active outlet transaction changed before unplug completed.');
        }
    }

    public function markRemoteStopRequested(int $chargerId, int $evseId, int $connectorId, string $transactionId, int $sequence): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE outlet_session SET seq_no = :seq_no, stop_reason = 'Remote'
            WHERE charger_id = :charger_id AND evse_id = :evse_id AND connector_id = :connector_id
                AND connector_status = 'Occupied' AND transaction_id = :transaction_id
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'evse_id' => $evseId,
            'connector_id' => $connectorId,
            'transaction_id' => $transactionId,
            'seq_no' => $sequence,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The active outlet transaction changed before remote stop completed.');
        }
    }

    public function recordMeterSample(
        int $chargerId,
        int $evseId,
        int $connectorId,
        string $transactionId,
        int $meterWh,
        int $sequence,
        string $sampledAt,
    ): void {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE outlet_session
            SET meter_wh = :meter_wh, seq_no = :seq_no, meter_updated_at = :sampled_at
            WHERE charger_id = :charger_id AND evse_id = :evse_id AND connector_id = :connector_id
                AND connector_status = 'Occupied' AND transaction_id = :transaction_id
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'evse_id' => $evseId,
            'connector_id' => $connectorId,
            'transaction_id' => $transactionId,
            'meter_wh' => $meterWh,
            'seq_no' => $sequence,
            'sampled_at' => $sampledAt,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The active outlet transaction changed before its meter sample was saved.');
        }
    }

    /**
     * @param array<string, mixed> $meterValue
     */
    public function recordTransactionEndSample(
        int $chargerId,
        int $evseId,
        int $connectorId,
        string $transactionId,
        int $meterWh,
        string $sampledAt,
        array $meterValue,
    ): void {
        $session = $this->find($chargerId, $evseId, $connectorId);
        if ($session === null || $session['connector_status'] !== 'Occupied' || $session['transaction_id'] !== $transactionId) {
            throw new RuntimeException('The active outlet transaction changed before its ending meter sample was saved.');
        }

        $samples = $session['meter_end_samples'];
        $samples[] = $meterValue;
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE outlet_session
            SET meter_wh = :meter_wh, meter_ended_at = :sampled_at, meter_end_samples = :meter_end_samples
            WHERE charger_id = :charger_id AND evse_id = :evse_id AND connector_id = :connector_id
                AND connector_status = 'Occupied' AND transaction_id = :transaction_id
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'evse_id' => $evseId,
            'connector_id' => $connectorId,
            'transaction_id' => $transactionId,
            'meter_wh' => $meterWh,
            'sampled_at' => $sampledAt,
            'meter_end_samples' => json_encode($samples, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The active outlet transaction changed before its ending meter sample was saved.');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{meter_updated_at:?string,meter_ended_at:?string,meter_end_samples:list<array<string,mixed>>}
     */
    private static function meterSampleFields(array $row): array
    {
        try {
            $samples = json_decode($row['meter_end_samples'], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The transaction ending meter samples contain invalid JSON.', previous: $exception);
        }

        if (!is_array($samples) || !array_is_list($samples)) {
            throw new RuntimeException('The transaction ending meter samples must be a JSON list.');
        }

        return [
            'meter_updated_at' => $row['meter_updated_at'],
            'meter_ended_at' => $row['meter_ended_at'],
            'meter_end_samples' => $samples,
        ];
    }

    public function hasActiveTransactions(int $chargerId): bool
    {
        $statement = $this->database->prepare("SELECT 1 FROM outlet_session WHERE charger_id = :charger_id AND connector_status = 'Occupied' LIMIT 1");
        $statement->execute(['charger_id' => $chargerId]);

        return $statement->fetchColumn() !== false;
    }
}
