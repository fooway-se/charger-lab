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

    public function allForCharger(int $chargerId): array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT evse_id, connector_id, connector_status, transaction_id, id_token, seq_no, meter_wh, stop_reason, meter_updated_at, meter_ended_at, meter_end_samples, remote_start_deadline, authorization_deadline, suspended_by
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
                'remote_start_deadline' => $row['remote_start_deadline'],
                'remote_start_pending' => $row['remote_start_deadline'] !== null,
                'authorization_deadline' => $row['authorization_deadline'],
                'authorization_pending' => $row['authorization_deadline'] !== null,
                'suspended_by' => $row['suspended_by'],
                ...self::meterSampleFields($row),
            ];
        }, $statement->fetchAll());
    }

    public function find(int $chargerId, int $evseId, int $connectorId): ?array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT evse_id, connector_id, connector_status, transaction_id, id_token, seq_no, meter_wh, stop_reason, meter_updated_at, meter_ended_at, meter_end_samples, remote_start_deadline, authorization_deadline, suspended_by
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
            'remote_start_deadline' => $row['remote_start_deadline'],
            'remote_start_pending' => $row['remote_start_deadline'] !== null,
            'authorization_deadline' => $row['authorization_deadline'],
            'authorization_pending' => $row['authorization_deadline'] !== null,
            'suspended_by' => $row['suspended_by'],
            ...self::meterSampleFields($row),
        ];
    }

    public function findByTransactionId(int $chargerId, string $transactionId): ?array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT evse_id, connector_id, connector_status, transaction_id, id_token, seq_no, meter_wh, stop_reason, meter_updated_at, meter_ended_at, meter_end_samples, remote_start_deadline, authorization_deadline, suspended_by
            FROM outlet_session
            WHERE charger_id = :charger_id AND transaction_id = :transaction_id
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
            'remote_start_deadline' => $row['remote_start_deadline'],
            'remote_start_pending' => $row['remote_start_deadline'] !== null,
            'authorization_deadline' => $row['authorization_deadline'],
            'authorization_pending' => $row['authorization_deadline'] !== null,
            'suspended_by' => $row['suspended_by'],
            ...self::meterSampleFields($row),
        ];
    }

    public function markOccupied(int $chargerId, int $evseId, int $connectorId, string $transactionId): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE outlet_session
            SET connector_status = 'Occupied', transaction_id = :transaction_id, id_token = NULL, seq_no = 0,
                remote_start_deadline = NULL, authorization_deadline = NULL,
                stop_reason = NULL, suspended_by = NULL, meter_updated_at = :meter_updated_at, meter_ended_at = :meter_ended_at, meter_end_samples = '[]'
            WHERE charger_id = :charger_id AND evse_id = :evse_id AND connector_id = :connector_id
                AND connector_status = 'Available' AND transaction_id IS NULL
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

    public function beginRemoteStart(int $chargerId, int $evseId, int $connectorId, string $transactionId, string $idToken, string $deadline): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE outlet_session
            SET connector_status = 'Occupied', transaction_id = :transaction_id, id_token = :id_token, seq_no = 0,
                stop_reason = NULL, suspended_by = NULL, meter_updated_at = NULL, meter_ended_at = NULL, meter_end_samples = '[]',
                remote_start_deadline = :deadline, authorization_deadline = NULL
            WHERE charger_id = :charger_id AND evse_id = :evse_id AND connector_id = :connector_id
                AND connector_status = 'Available' AND transaction_id IS NULL
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'evse_id' => $evseId,
            'connector_id' => $connectorId,
            'transaction_id' => $transactionId,
            'id_token' => $idToken,
            'deadline' => $deadline,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The selected outlet is no longer available for remote start.');
        }
    }

    public function beginAuthorizationFirst(int $chargerId, int $evseId, int $connectorId, string $transactionId, string $idToken, string $deadline): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE outlet_session
            SET transaction_id = :transaction_id, id_token = :id_token, seq_no = 0,
                remote_start_deadline = NULL, authorization_deadline = :deadline,
                stop_reason = NULL, suspended_by = NULL, meter_updated_at = NULL, meter_ended_at = NULL, meter_end_samples = '[]'
            WHERE charger_id = :charger_id AND evse_id = :evse_id AND connector_id = :connector_id
                AND connector_status = 'Available' AND transaction_id IS NULL
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'evse_id' => $evseId,
            'connector_id' => $connectorId,
            'transaction_id' => $transactionId,
            'id_token' => $idToken,
            'deadline' => $deadline,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The selected outlet is no longer available for authorization-first start.');
        }
    }

    public function dueAuthorizationFirstStarts(int $chargerId, string $now): array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT charger_id, evse_id, connector_id, transaction_id, seq_no, meter_wh
            FROM outlet_session
            WHERE charger_id = :charger_id AND authorization_deadline IS NOT NULL AND authorization_deadline <= :now
            ORDER BY authorization_deadline, charger_id, evse_id, connector_id
            SQL);
        $statement->execute(['charger_id' => $chargerId, 'now' => $now]);

        return array_map(static fn (array $row): array => [
            'charger_id' => (int) $row['charger_id'],
            'evse_id' => (int) $row['evse_id'],
            'connector_id' => (int) $row['connector_id'],
            'transaction_id' => (string) $row['transaction_id'],
            'seq_no' => (int) $row['seq_no'],
            'meter_wh' => (int) $row['meter_wh'],
        ], $statement->fetchAll());
    }

    public function markAuthorizationFirstConnected(int $chargerId, int $evseId, int $connectorId, string $transactionId, int $sequence): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE outlet_session
            SET connector_status = 'Occupied', authorization_deadline = NULL,
                seq_no = :seq_no, meter_updated_at = :meter_updated_at, meter_ended_at = :meter_ended_at
            WHERE charger_id = :charger_id AND evse_id = :evse_id AND connector_id = :connector_id
                AND connector_status = 'Available' AND transaction_id = :transaction_id
                AND authorization_deadline IS NOT NULL
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'evse_id' => $evseId,
            'connector_id' => $connectorId,
            'transaction_id' => $transactionId,
            'seq_no' => $sequence,
            'meter_updated_at' => gmdate(DATE_ATOM),
            'meter_ended_at' => gmdate(DATE_ATOM),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The authorization-first transaction is no longer waiting for a vehicle.');
        }
    }

    public function dueRemoteStarts(int $chargerId, string $now): array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT charger_id, evse_id, connector_id, transaction_id, seq_no, meter_wh
            FROM outlet_session
            WHERE charger_id = :charger_id AND remote_start_deadline IS NOT NULL AND remote_start_deadline <= :now
            ORDER BY remote_start_deadline, charger_id, evse_id, connector_id
            SQL);
        $statement->execute(['charger_id' => $chargerId, 'now' => $now]);

        return array_map(static fn (array $row): array => [
            'charger_id' => (int) $row['charger_id'],
            'evse_id' => (int) $row['evse_id'],
            'connector_id' => (int) $row['connector_id'],
            'transaction_id' => (string) $row['transaction_id'],
            'seq_no' => (int) $row['seq_no'],
            'meter_wh' => (int) $row['meter_wh'],
        ], $statement->fetchAll());
    }

    public function markRemoteStartConnected(int $chargerId, int $evseId, int $connectorId, string $transactionId, int $sequence): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE outlet_session
            SET remote_start_deadline = NULL, seq_no = :seq_no, meter_updated_at = :meter_updated_at
            WHERE charger_id = :charger_id AND evse_id = :evse_id AND connector_id = :connector_id
                AND connector_status = 'Occupied' AND transaction_id = :transaction_id
                AND remote_start_deadline IS NOT NULL
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'evse_id' => $evseId,
            'connector_id' => $connectorId,
            'transaction_id' => $transactionId,
            'seq_no' => $sequence,
            'meter_updated_at' => gmdate(DATE_ATOM),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The remote-start transaction is no longer waiting for a vehicle.');
        }
    }

    public function updateSequence(int $chargerId, int $evseId, int $connectorId, string $transactionId, int $sequence): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE outlet_session SET seq_no = :seq_no, meter_updated_at = :meter_updated_at
            WHERE charger_id = :charger_id AND evse_id = :evse_id AND connector_id = :connector_id
                AND connector_status = 'Occupied' AND transaction_id = :transaction_id
                AND remote_start_deadline IS NULL
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'evse_id' => $evseId,
            'connector_id' => $connectorId,
            'transaction_id' => $transactionId,
            'seq_no' => $sequence,
            'meter_updated_at' => gmdate(DATE_ATOM),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The active transaction changed before its sequence was saved.');
        }
    }

    public function advanceSequenceForTrigger(
        int $chargerId,
        int $evseId,
        int $connectorId,
        string $transactionId,
        int $previousSequence,
    ): void {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE outlet_session SET seq_no = :next_sequence
            WHERE charger_id = :charger_id AND evse_id = :evse_id AND connector_id = :connector_id
                AND transaction_id = :transaction_id
                AND (connector_status = 'Occupied' OR (connector_status = 'Available' AND authorization_deadline IS NOT NULL))
                AND seq_no = :previous_sequence
            SQL);
        $statement->execute([
            'next_sequence' => $previousSequence + 1,
            'charger_id' => $chargerId,
            'evse_id' => $evseId,
            'connector_id' => $connectorId,
            'transaction_id' => $transactionId,
            'previous_sequence' => $previousSequence,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The active transaction changed before its triggered event was stored.');
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

    public function clearToken(int $chargerId, int $evseId, int $connectorId, string $transactionId, int $sequence): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE outlet_session SET id_token = NULL, seq_no = :seq_no
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
            throw new RuntimeException('The active outlet transaction changed before its rejected token was cleared.');
        }
    }

    public function markAvailable(int $chargerId, int $evseId, int $connectorId, string $transactionId, int $meterWh): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE outlet_session
            SET connector_status = 'Available', transaction_id = NULL, id_token = NULL, seq_no = 0,
                remote_start_deadline = NULL, authorization_deadline = NULL,
                meter_wh = :meter_wh, stop_reason = NULL, suspended_by = NULL,
                meter_updated_at = NULL, meter_ended_at = NULL, meter_end_samples = '[]'
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

    public function setChargingSuspension(
        int $chargerId,
        int $evseId,
        int $connectorId,
        string $transactionId,
        ?string $suspendedBy,
        int $sequence,
        ?int $previousSequence = null,
        bool $clearAuthorization = false,
    ): void {
        if ($suspendedBy !== null && !in_array($suspendedBy, ['EV', 'EVSE'], true)) {
            throw new RuntimeException('Charging suspension must be controlled by the EV or EVSE.');
        }

        $sequenceCondition = $previousSequence === null ? '' : 'AND seq_no = :previous_sequence';
        $authorizationUpdate = $clearAuthorization ? ', id_token = NULL' : '';
        $statement = $this->database->prepare(<<<SQL
            UPDATE outlet_session
            SET suspended_by = :suspended_by, seq_no = :seq_no{$authorizationUpdate}
            WHERE charger_id = :charger_id AND evse_id = :evse_id AND connector_id = :connector_id
                AND connector_status = 'Occupied' AND transaction_id = :transaction_id
                AND id_token IS NOT NULL AND authorization_deadline IS NULL
                AND remote_start_deadline IS NULL AND stop_reason IS NULL
                {$sequenceCondition}
            SQL);
        $parameters = [
            'charger_id' => $chargerId,
            'evse_id' => $evseId,
            'connector_id' => $connectorId,
            'transaction_id' => $transactionId,
            'suspended_by' => $suspendedBy,
            'seq_no' => $sequence,
        ];
        if ($previousSequence !== null) {
            $parameters['previous_sequence'] = $previousSequence;
        }
        $statement->execute($parameters);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Charging suspension requires the matching active authorized transaction.');
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

    public function markLocalStopRequested(
        int $chargerId,
        int $evseId,
        int $connectorId,
        string $transactionId,
        string $idToken,
        int $sequence,
    ): void {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE outlet_session SET seq_no = :seq_no, stop_reason = 'Local'
            WHERE charger_id = :charger_id AND evse_id = :evse_id AND connector_id = :connector_id
                AND connector_status = 'Occupied' AND transaction_id = :transaction_id
                AND id_token = :id_token AND stop_reason IS NULL AND remote_start_deadline IS NULL
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
            throw new RuntimeException('The active outlet transaction changed before local stop completed.');
        }
    }

    public function recordMeterSample(
        int $chargerId,
        int $evseId,
        int $connectorId,
        string $transactionId,
        int $meterWh,
        int $sequence,
        ?string $periodicSampledAt,
    ): void {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE outlet_session
            SET meter_wh = :meter_wh, seq_no = :seq_no,
                meter_updated_at = COALESCE(:periodic_sampled_at, meter_updated_at)
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
            'periodic_sampled_at' => $periodicSampledAt,
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The active outlet transaction changed before its meter sample was saved.');
        }
    }

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
        $statement = $this->database->prepare('SELECT 1 FROM outlet_session WHERE charger_id = :charger_id AND transaction_id IS NOT NULL LIMIT 1');
        $statement->execute(['charger_id' => $chargerId]);

        return $statement->fetchColumn() !== false;
    }
}
