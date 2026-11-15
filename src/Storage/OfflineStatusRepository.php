<?php

declare(strict_types=1);

namespace ChargerEmulator\Storage;

use PDO;

/**
 * Persists status changes made while an accepted charging station is offline.
 */
final class OfflineStatusRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function begin(int $chargerId, ?float $offlineSince = null): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            INSERT INTO charger_offline_state (charger_id, offline_since)
            VALUES (:charger_id, :offline_since)
            ON CONFLICT (charger_id) DO NOTHING
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'offline_since' => $offlineSince ?? microtime(true),
        ]);
    }

    public function markConnectorChanged(int $chargerId, int $evseId, int $connectorId): void
    {
        $this->begin($chargerId);
        $statement = $this->database->prepare(<<<'SQL'
            INSERT INTO charger_offline_connector_change (charger_id, evse_id, connector_id)
            VALUES (:charger_id, :evse_id, :connector_id)
            ON CONFLICT (charger_id, evse_id, connector_id) DO NOTHING
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'evse_id' => $evseId,
            'connector_id' => $connectorId,
        ]);
    }

    /** @return null|array{offline_since:float,changed_connectors:list<array{evse_id:int,connector_id:int}>} */
    public function pending(int $chargerId): ?array
    {
        $statement = $this->database->prepare('SELECT offline_since FROM charger_offline_state WHERE charger_id = :charger_id');
        $statement->execute(['charger_id' => $chargerId]);
        $offlineSince = $statement->fetchColumn();
        if ($offlineSince === false) {
            return null;
        }

        $statement = $this->database->prepare(<<<'SQL'
            SELECT evse_id, connector_id FROM charger_offline_connector_change
            WHERE charger_id = :charger_id ORDER BY evse_id, connector_id
            SQL);
        $statement->execute(['charger_id' => $chargerId]);

        return [
            'offline_since' => (float) $offlineSince,
            'changed_connectors' => array_map(static fn (array $connector): array => [
                'evse_id' => (int) $connector['evse_id'],
                'connector_id' => (int) $connector['connector_id'],
            ], $statement->fetchAll()),
        ];
    }

    public function clear(int $chargerId): void
    {
        $statement = $this->database->prepare('DELETE FROM charger_offline_connector_change WHERE charger_id = :charger_id');
        $statement->execute(['charger_id' => $chargerId]);
        $statement = $this->database->prepare('DELETE FROM charger_offline_state WHERE charger_id = :charger_id');
        $statement->execute(['charger_id' => $chargerId]);
    }
}
