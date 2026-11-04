<?php

declare(strict_types=1);

namespace ChargerEmulator\Storage;

use PDO;

/**
 * Persists and retrieves OCPP exchange entries for individual chargers.
 */
final class ExchangeRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function record(int $chargerId, string $direction, string $action, string $message): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO exchange_log (charger_id, direction, action, message, created_at) VALUES (:charger_id, :direction, :action, :message, :created_at)',
        );
        $statement->execute([
            'charger_id' => $chargerId,
            'direction' => $direction,
            'action' => $action,
            'message' => $message,
            'created_at' => gmdate(DATE_ATOM),
        ]);
    }

    public function clear(int $chargerId): void
    {
        $statement = $this->database->prepare('DELETE FROM exchange_log WHERE charger_id = :charger_id');
        $statement->execute(['charger_id' => $chargerId]);
    }

    /**
     * @return list<array{id:int,charger_id:int,direction:string,action:string,message:string,created_at:string}>
     */
    public function recent(int $chargerId, int $limit = 30): array
    {
        $statement = $this->database->prepare('SELECT * FROM exchange_log WHERE charger_id = :charger_id ORDER BY id DESC LIMIT :limit');
        $statement->bindValue(':charger_id', $chargerId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_reverse($statement->fetchAll());
    }
}
