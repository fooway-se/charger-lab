<?php

declare(strict_types=1);

namespace ChargerEmulator\Storage;

use PDO;
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
     * @return list<array{evse_id:int,connector_id:int,connector_status:string,transaction_id:?string,id_token:?string,seq_no:int,meter_wh:int}>
     */
    public function allForCharger(int $chargerId): array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT evse_id, connector_id, connector_status, transaction_id, id_token, seq_no, meter_wh
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
            ];
        }, $statement->fetchAll());
    }

    /**
     * @return array{evse_id:int,connector_id:int,connector_status:string,transaction_id:?string,id_token:?string,seq_no:int,meter_wh:int}|null
     */
    public function find(int $chargerId, int $evseId, int $connectorId): ?array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT evse_id, connector_id, connector_status, transaction_id, id_token, seq_no, meter_wh
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
        ];
    }

    public function markOccupied(int $chargerId, int $evseId, int $connectorId, string $transactionId): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE outlet_session
            SET connector_status = 'Occupied', transaction_id = :transaction_id, id_token = NULL, seq_no = 0
            WHERE charger_id = :charger_id AND evse_id = :evse_id AND connector_id = :connector_id
                AND connector_status = 'Available'
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'evse_id' => $evseId,
            'connector_id' => $connectorId,
            'transaction_id' => $transactionId,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The selected outlet is not available.');
        }
    }

    public function attachToken(int $chargerId, int $evseId, int $connectorId, string $transactionId, string $idToken, int $sequence): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE outlet_session SET id_token = :id_token, seq_no = :seq_no
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
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The active outlet transaction changed before authorization completed.');
        }
    }

    public function markAvailable(int $chargerId, int $evseId, int $connectorId, string $transactionId, int $meterWh): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE outlet_session
            SET connector_status = 'Available', transaction_id = NULL, id_token = NULL, seq_no = 0, meter_wh = :meter_wh
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

    public function hasActiveTransactions(int $chargerId): bool
    {
        $statement = $this->database->prepare("SELECT 1 FROM outlet_session WHERE charger_id = :charger_id AND connector_status = 'Occupied' LIMIT 1");
        $statement->execute(['charger_id' => $chargerId]);

        return $statement->fetchColumn() !== false;
    }
}
