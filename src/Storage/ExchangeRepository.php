<?php

declare(strict_types=1);

namespace ChargerEmulator\Storage;

use PDO;

/**
 * Persists and retrieves the most recent OCPP exchange entries.
 */
final class ExchangeRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function record(string $direction, string $action, string $message): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO exchange_log (direction, action, message, created_at) VALUES (:direction, :action, :message, :created_at)',
        );
        $statement->execute([
            'direction' => $direction,
            'action' => $action,
            'message' => $message,
            'created_at' => gmdate(DATE_ATOM),
        ]);
    }

    public function clear(): void
    {
        $this->database->exec('DELETE FROM exchange_log');
    }

    /**
     * @return list<array{id:int,direction:string,action:string,message:string,created_at:string}>
     */
    public function recent(int $limit = 30): array
    {
        $statement = $this->database->prepare('SELECT * FROM exchange_log ORDER BY id DESC LIMIT :limit');
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_reverse($statement->fetchAll());
    }
}
