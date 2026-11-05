<?php

declare(strict_types=1);

namespace ChargerEmulator\Storage;

use JsonException;
use PDO;
use RuntimeException;

/**
 * Queues OCPP actions for the long-running station session worker.
 */
final class OcppCommandRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function enqueue(int $chargerId, string $action, array $payload): int
    {
        $statement = $this->database->prepare(<<<'SQL'
            INSERT INTO ocpp_command (charger_id, action, payload, status, created_at)
            VALUES (:charger_id, :action, :payload, 'pending', :created_at)
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'action' => $action,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at' => gmdate(DATE_ATOM),
        ]);

        return (int) $this->database->lastInsertId();
    }

    public function hasPendingBootNotification(int $chargerId): bool
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT 1 FROM ocpp_command
            WHERE charger_id = :charger_id
                AND action = 'BootNotification'
                AND status IN ('pending', 'processing')
            LIMIT 1
            SQL);
        $statement->execute(['charger_id' => $chargerId]);

        return $statement->fetchColumn() !== false;
    }

    public function claimNext(): ?array
    {
        $this->database->beginTransaction();
        try {
            $command = $this->database->query("SELECT * FROM ocpp_command WHERE status = 'pending' ORDER BY id LIMIT 1")->fetch();
            if ($command === false) {
                $this->database->commit();

                return null;
            }

            $statement = $this->database->prepare("UPDATE ocpp_command SET status = 'processing' WHERE id = :id AND status = 'pending'");
            $statement->execute(['id' => $command['id']]);
            if ($statement->rowCount() !== 1) {
                $this->database->commit();

                return null;
            }

            $this->database->commit();
            $payload = json_decode($command['payload'], true, flags: JSON_THROW_ON_ERROR);

            return [
                'id' => (int) $command['id'],
                'charger_id' => (int) $command['charger_id'],
                'action' => $command['action'],
                'payload' => $payload,
            ];
        } catch (\Throwable $exception) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }

            throw $exception;
        }
    }

    public function complete(int $id, array $result): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE ocpp_command SET status = 'completed', result = :result, completed_at = :completed_at
            WHERE id = :id AND status = 'processing'
            SQL);
        $statement->execute([
            'result' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'completed_at' => gmdate(DATE_ATOM),
            'id' => $id,
        ]);
    }

    public function fail(int $id, string $error): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE ocpp_command SET status = 'failed', error = :error, completed_at = :completed_at
            WHERE id = :id AND status = 'processing'
            SQL);
        $statement->execute(['error' => $error, 'completed_at' => gmdate(DATE_ATOM), 'id' => $id]);
    }

    public function recoverInterrupted(): void
    {
        $this->database->exec(<<<'SQL'
            UPDATE ocpp_command
            SET status = 'pending', result = NULL, error = NULL, completed_at = NULL
            WHERE status = 'processing' AND action = 'BootNotification'
            SQL);
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE ocpp_command
            SET status = 'failed', error = 'The station worker restarted before this action completed.', completed_at = :completed_at
            WHERE status = 'processing' AND action <> 'BootNotification'
            SQL);
        $statement->execute(['completed_at' => gmdate(DATE_ATOM)]);
    }

    public function get(int $id): ?array
    {
        $statement = $this->database->prepare('SELECT * FROM ocpp_command WHERE id = :id');
        $statement->execute(['id' => $id]);
        $command = $statement->fetch();
        if ($command === false) {
            return null;
        }

        try {
            $command['payload'] = json_decode($command['payload'], true, flags: JSON_THROW_ON_ERROR);
            $command['result'] = $command['result'] === null
                ? null
                : json_decode($command['result'], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('A queued OCPP command contains invalid JSON.', previous: $exception);
        }

        return $command;
    }

    public function waitForCompletion(int $id, int $timeoutMilliseconds = 20_000): ?array
    {
        $deadline = microtime(true) + ($timeoutMilliseconds / 1000);
        do {
            $command = $this->get($id);
            if ($command === null || in_array($command['status'], ['completed', 'failed'], true)) {
                return $command;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        return $this->get($id);
    }
}
