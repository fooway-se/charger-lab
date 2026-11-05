<?php

declare(strict_types=1);

namespace ChargerEmulator\Storage;

use PDO;

/**
 * Stores station-wide reset requests until the worker completes the reset.
 */
final class ResetRepository
{
    public function __construct(
        private readonly PDO $database,
        private readonly OutletSessionRepository $outletSessions,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function request(int $chargerId, array $payload): string
    {
        $type = $payload['type'] ?? null;
        if (!is_string($type) || !in_array($type, ['Immediate', 'OnIdle'], true)
            || array_key_exists('evseId', $payload)) {
            return 'Rejected';
        }

        $hasActiveTransactions = array_any(
            $this->outletSessions->allForCharger($chargerId),
            static fn (array $session): bool => $session['connector_status'] === 'Occupied',
        );
        $status = $type === 'OnIdle' && $hasActiveTransactions ? 'Scheduled' : 'Accepted';
        $statement = $this->database->prepare(<<<'SQL'
            INSERT INTO reset_request (charger_id, reset_type, response_status, requested_at)
            VALUES (:charger_id, :reset_type, :response_status, :requested_at)
            ON CONFLICT(charger_id) DO UPDATE SET
                reset_type = excluded.reset_type,
                response_status = excluded.response_status,
                reset_stage = 'waiting',
                requested_at = excluded.requested_at
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'reset_type' => $type,
            'response_status' => $status,
            'requested_at' => gmdate(DATE_ATOM),
        ]);

        return $status;
    }

    /**
     * @return array{type:string,status:string}|null
     */
    public function pending(int $chargerId): ?array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT reset_type, response_status FROM reset_request WHERE charger_id = :charger_id
            SQL);
        $statement->execute(['charger_id' => $chargerId]);
        $request = $statement->fetch();
        if ($request === false) {
            return null;
        }

        return ['type' => $request['reset_type'], 'status' => $request['response_status']];
    }

    public function blocksNewTransactions(int $chargerId): bool
    {
        return $this->pending($chargerId) !== null;
    }

    public function isReconnecting(int $chargerId): bool
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT reset_stage FROM reset_request WHERE charger_id = :charger_id
            SQL);
        $statement->execute(['charger_id' => $chargerId]);

        return $statement->fetchColumn() === 'reconnecting';
    }

    public function markReconnecting(int $chargerId): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE reset_request SET reset_stage = 'reconnecting' WHERE charger_id = :charger_id
            SQL);
        $statement->execute(['charger_id' => $chargerId]);
    }

    public function markUnavailableReported(int $chargerId): bool
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE reset_request
            SET reset_stage = 'unavailable_reported'
            WHERE charger_id = :charger_id AND reset_stage = 'waiting'
            SQL);
        $statement->execute(['charger_id' => $chargerId]);

        return $statement->rowCount() === 1;
    }

    public function unavailableReported(int $chargerId): bool
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT reset_stage FROM reset_request WHERE charger_id = :charger_id
            SQL);
        $statement->execute(['charger_id' => $chargerId]);

        return $statement->fetchColumn() === 'unavailable_reported';
    }

    public function complete(int $chargerId): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            DELETE FROM reset_request WHERE charger_id = :charger_id
            SQL);
        $statement->execute(['charger_id' => $chargerId]);
    }
}
