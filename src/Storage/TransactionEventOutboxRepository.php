<?php

declare(strict_types=1);

namespace ChargerEmulator\Storage;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Persists and sequences TransactionEvent messages until delivery is acknowledged.
 */
final class TransactionEventOutboxRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function enqueue(int $chargerId, array $payload): array
    {
        $transactionInfo = is_array($payload['transactionInfo'] ?? null) ? $payload['transactionInfo'] : [];
        $transactionId = $transactionInfo['transactionId'] ?? null;
        $sequence = $payload['seqNo'] ?? null;
        if (!is_string($transactionId) || $transactionId === '' || strlen($transactionId) > 36
            || !is_int($sequence) || $sequence < 0) {
            throw new RuntimeException('A TransactionEvent outbox payload requires a valid transaction ID and sequence number.');
        }

        $messageId = bin2hex(random_bytes(16));
        $statement = $this->database->prepare(<<<'SQL'
            INSERT OR IGNORE INTO transaction_event_outbox
                (charger_id, transaction_id, seq_no, message_id, payload, status)
            VALUES (:charger_id, :transaction_id, :seq_no, :message_id, :payload, 'pending')
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'transaction_id' => $transactionId,
            'seq_no' => $sequence,
            'message_id' => $messageId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
        $entry = $this->getByTransactionSequence($chargerId, $transactionId, $sequence);
        if ($entry === null) {
            throw new RuntimeException('The TransactionEvent could not be stored in the outbox.');
        }

        return $entry;
    }

    /**
     * Stores a triggered event and advances its transaction sequence atomically.
     *
     * @param array<string, mixed> $payload
     * @return array{id:int,charger_id:int,transaction_id:string,seq_no:int,message_id:string,payload:array<string,mixed>,status:string,attempt_count:int,next_attempt_at:?int}
     */
    public function enqueueForActiveTransaction(
        int $chargerId,
        array $payload,
        OutletSessionRepository $sessions,
        int $evseId,
        int $connectorId,
        string $transactionId,
        int $previousSequence,
        ?string $suspendedBy = null,
        bool $updateSuspension = false,
    ): array {
        $transactionInfo = is_array($payload['transactionInfo'] ?? null) ? $payload['transactionInfo'] : [];
        $eventTransactionId = $transactionInfo['transactionId'] ?? null;
        if ($eventTransactionId !== $transactionId || ($payload['seqNo'] ?? null) !== $previousSequence + 1) {
            throw new RuntimeException('A triggered event must match the active transaction and next sequence number.');
        }

        if ($this->database->inTransaction()) {
            throw new RuntimeException('A triggered transaction event cannot start inside another database transaction.');
        }

        $this->database->beginTransaction();
        try {
            $entry = $this->enqueue($chargerId, $payload);
            if ($updateSuspension) {
                $sessions->setChargingSuspension(
                    $chargerId,
                    $evseId,
                    $connectorId,
                    $transactionId,
                    $suspendedBy,
                    $previousSequence + 1,
                    $previousSequence,
                );
            } else {
                $sessions->advanceSequenceForTrigger(
                    $chargerId,
                    $evseId,
                    $connectorId,
                    $transactionId,
                    $previousSequence,
                );
            }
            $this->database->commit();

            return $entry;
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }

            throw $exception;
        }
    }

    public function nextPending(int $chargerId, ?int $now = null): ?array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT current.* FROM transaction_event_outbox AS current
            WHERE current.charger_id = :charger_id
                AND current.status = 'pending'
                AND (current.next_attempt_at IS NULL OR current.next_attempt_at <= :now)
                AND NOT EXISTS (
                    SELECT 1 FROM transaction_event_outbox AS earlier
                    WHERE earlier.charger_id = current.charger_id
                        AND earlier.status = 'pending'
                        AND earlier.id < current.id
                )
            LIMIT 1
            SQL);
        $statement->execute(['charger_id' => $chargerId, 'now' => $now ?? time()]);
        $row = $statement->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    public function recordFailure(int $id, string $error, int $maxAttempts, int $intervalSeconds, ?int $now = null): void
    {
        $statement = $this->database->prepare('SELECT attempt_count FROM transaction_event_outbox WHERE id = :id AND status = \'pending\'');
        $statement->execute(['id' => $id]);
        $attemptCount = $statement->fetchColumn();
        if ($attemptCount === false) {
            return;
        }

        $nextAttemptCount = (int) $attemptCount + 1;
        $discard = $nextAttemptCount >= max(1, $maxAttempts);
        $nextAttemptAt = $discard ? null : ($now ?? time()) + (max(0, $intervalSeconds) * $nextAttemptCount);
        $update = $this->database->prepare(<<<'SQL'
            UPDATE transaction_event_outbox
            SET status = :status, attempt_count = :attempt_count, next_attempt_at = :next_attempt_at,
                last_error = :last_error, completed_at = :completed_at
            WHERE id = :id AND status = 'pending'
            SQL);
        $update->execute([
            'status' => $discard ? 'discarded' : 'pending',
            'attempt_count' => $nextAttemptCount,
            'next_attempt_at' => $nextAttemptAt,
            'last_error' => $error,
            'completed_at' => $discard ? gmdate(DATE_ATOM) : null,
            'id' => $id,
        ]);
    }

    public function markDelivered(int $id): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE transaction_event_outbox
            SET status = 'delivered', next_attempt_at = NULL, last_error = NULL, completed_at = :completed_at
            WHERE id = :id AND status = 'pending'
            SQL);
        $statement->execute(['id' => $id, 'completed_at' => gmdate(DATE_ATOM)]);
    }

    public function markPendingOffline(int $chargerId): void
    {
        $this->database->beginTransaction();
        try {
            $select = $this->database->prepare(<<<'SQL'
                SELECT id, payload FROM transaction_event_outbox
                WHERE charger_id = :charger_id AND status = 'pending'
                ORDER BY id
                SQL);
            $select->execute(['charger_id' => $chargerId]);
            $update = $this->database->prepare(<<<'SQL'
                UPDATE transaction_event_outbox SET payload = :payload
                WHERE id = :id AND status = 'pending'
                SQL);
            foreach ($select->fetchAll() as $row) {
                $payload = json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($payload)) {
                    throw new RuntimeException('A TransactionEvent outbox payload must be a JSON object.');
                }

                $payload['offline'] = true;
                $update->execute([
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'id' => $row['id'],
                ]);
            }

            $this->database->commit();
        } catch (Throwable $exception) {
            $this->database->rollBack();
            throw $exception;
        }
    }

    public function hasPending(int $chargerId, ?string $transactionId = null): bool
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT 1 FROM transaction_event_outbox
            WHERE charger_id = :charger_id AND status = 'pending'
                AND (:transaction_id IS NULL OR transaction_id = :transaction_id)
            LIMIT 1
            SQL);
        $statement->execute(['charger_id' => $chargerId, 'transaction_id' => $transactionId]);

        return $statement->fetchColumn() !== false;
    }

    private function getByTransactionSequence(int $chargerId, string $transactionId, int $sequence): ?array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT * FROM transaction_event_outbox
            WHERE charger_id = :charger_id AND transaction_id = :transaction_id AND seq_no = :seq_no
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'transaction_id' => $transactionId,
            'seq_no' => $sequence,
        ]);
        $row = $statement->fetch();

        return $row === false ? null : self::hydrate($row);
    }

    private static function hydrate(array $row): array
    {
        $payload = json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('A TransactionEvent outbox payload must be a JSON object.');
        }

        return [
            'id' => (int) $row['id'],
            'charger_id' => (int) $row['charger_id'],
            'transaction_id' => $row['transaction_id'],
            'seq_no' => (int) $row['seq_no'],
            'message_id' => $row['message_id'],
            'payload' => $payload,
            'status' => $row['status'],
            'attempt_count' => (int) $row['attempt_count'],
            'next_attempt_at' => $row['next_attempt_at'] === null ? null : (int) $row['next_attempt_at'],
        ];
    }
}
