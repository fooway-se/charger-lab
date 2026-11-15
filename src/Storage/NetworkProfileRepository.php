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

    public function hasConfiguredSlot(int $chargerId, int $configurationSlot): bool
    {
        // Slot zero is backed by the emulator's existing connection settings unless
        // the CSMS has explicitly provisioned a profile in that slot.
        return $configurationSlot === 0 || $this->get($chargerId, $configurationSlot) !== null;
    }

    public function prioritized(int $chargerId, array $charger, string $priority): array
    {
        $profiles = [];
        foreach (explode(',', $priority) as $slot) {
            $slot = (int) $slot;
            $connectionData = $this->get($chargerId, $slot);
            if ($connectionData === null && $slot === 0) {
                $secure = str_starts_with($charger['csms_url'], 'wss://');
                $connectionData = [
                    'ocppVersion' => 'OCPP20',
                    'ocppTransport' => 'JSON',
                    'ocppCsmsUrl' => $charger['csms_url'],
                    'messageTimeout' => 8,
                    'securityProfile' => $secure ? 2 : 1,
                    'ocppInterface' => 'Wired0',
                ];
            }

            if ($connectionData !== null) {
                $profiles[] = [
                    'slot' => $slot,
                    'connectionData' => $connectionData,
                    'username' => $charger['station_identity'],
                    'password' => $charger['csms_password'],
                ];
            }
        }

        $lastSuccessful = $this->lastSuccessful($chargerId);
        $lastSuccessfulIsPrioritized = array_any($profiles, static fn (array $profile): bool => [
            'connectionData' => $profile['connectionData'],
            'username' => $profile['username'],
            'password' => $profile['password'],
        ] === $lastSuccessful);
        if ($lastSuccessful !== null && !$lastSuccessfulIsPrioritized) {
            $profiles[] = ['slot' => 'last-successful', ...$lastSuccessful];
        }

        return $profiles;
    }

    public function rememberSuccessful(int $chargerId, array $connectionData, string $username, string $password): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            INSERT INTO network_profile_state (charger_id, last_successful_profile)
            VALUES (:charger_id, :profile)
            ON CONFLICT (charger_id) DO UPDATE SET last_successful_profile = excluded.last_successful_profile
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'profile' => json_encode([
                'connectionData' => $connectionData,
                'username' => $username,
                'password' => $password,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function lastSuccessful(int $chargerId): ?array
    {
        $statement = $this->database->prepare('SELECT last_successful_profile FROM network_profile_state WHERE charger_id = :charger_id');
        $statement->execute(['charger_id' => $chargerId]);
        $profile = $statement->fetchColumn();

        return $profile === false ? null : json_decode($profile, true, flags: JSON_THROW_ON_ERROR);
    }
}
