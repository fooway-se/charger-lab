<?php

declare(strict_types=1);

namespace ChargerEmulator\Storage;

use PDO;

/**
 * Stores supported OCPP network profiles by charger and configuration slot.
 */
final class NetworkProfileRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function set(int $chargerId, array $connectionData, int $configurationSlot = 1): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            INSERT INTO network_profile (charger_id, configuration_slot, connection_data)
            VALUES (:charger_id, :configuration_slot, :connection_data)
            ON CONFLICT (charger_id, configuration_slot) DO UPDATE SET connection_data = excluded.connection_data
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'configuration_slot' => $configurationSlot,
            'connection_data' => json_encode($connectionData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function get(int $chargerId, int $configurationSlot = 1): ?array
    {
        $statement = $this->database->prepare(<<<'SQL'
            SELECT connection_data FROM network_profile
            WHERE charger_id = :charger_id AND configuration_slot = :configuration_slot
            SQL);
        $statement->execute(['charger_id' => $chargerId, 'configuration_slot' => $configurationSlot]);
        $connectionData = $statement->fetchColumn();

        return $connectionData === false ? null : json_decode($connectionData, true, flags: JSON_THROW_ON_ERROR);
    }
}
