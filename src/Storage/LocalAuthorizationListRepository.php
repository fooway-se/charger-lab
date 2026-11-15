<?php

declare(strict_types=1);

namespace ChargerEmulator\Storage;

use PDO;

/**
 * Persists each charger's versioned OCPP local authorization list.
 */
final class LocalAuthorizationListRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function version(int $chargerId): int
    {
        $statement = $this->database->prepare('SELECT version_number FROM local_authorization_list WHERE charger_id = :charger_id');
        $statement->execute(['charger_id' => $chargerId]);
        $version = $statement->fetchColumn();

        return $version === false ? 0 : (int) $version;
    }

    public function entries(int $chargerId): array
    {
        $statement = $this->database->prepare('SELECT entries FROM local_authorization_list WHERE charger_id = :charger_id');
        $statement->execute(['charger_id' => $chargerId]);
        $entries = $statement->fetchColumn();

        return $entries === false ? [] : json_decode((string) $entries, true, flags: JSON_THROW_ON_ERROR);
    }

    /** Remove the local customer data selected by a CustomerInformation clear request. */
    public function clearCustomerInformation(int $chargerId, ?array $idToken): void
    {
        $entries = $this->entries($chargerId);
        $remainingEntries = array_values(array_filter(
            $entries,
            static function (array $entry) use ($idToken): bool {
                if ($idToken === null) {
                    return false;
                }

                $entryToken = $entry['idToken'] ?? [];

                return ($entryToken['idToken'] ?? null) !== ($idToken['idToken'] ?? null)
                    || ($entryToken['type'] ?? null) !== ($idToken['type'] ?? null);
            },
        ));
        if ($remainingEntries === $entries) {
            return;
        }

        $statement = $this->database->prepare('UPDATE local_authorization_list SET entries = :entries WHERE charger_id = :charger_id');
        $statement->execute([
            'charger_id' => $chargerId,
            'entries' => json_encode($remainingEntries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function apply(int $chargerId, int $versionNumber, string $updateType, array $updates): string
    {
        $this->database->beginTransaction();
        $currentVersion = $this->version($chargerId);
        if ($updateType === 'Differential' && $versionNumber <= $currentVersion) {
            $this->database->rollBack();

            return 'VersionMismatch';
        }

        if ($updateType === 'Full') {
            $entries = $updates;
        } else {
            $entriesByToken = [];
            foreach ($this->entries($chargerId) as $entry) {
                $idToken = $entry['idToken'];
                $entriesByToken[$idToken['type'] . "\0" . $idToken['idToken']] = $entry;
            }
            foreach ($updates as $entry) {
                $idToken = $entry['idToken'];
                $key = $idToken['type'] . "\0" . $idToken['idToken'];
                if (!array_key_exists('idTokenInfo', $entry)) {
                    unset($entriesByToken[$key]);
                    continue;
                }

                $entriesByToken[$key] = $entry;
            }
            $entries = array_values($entriesByToken);
        }

        $statement = $this->database->prepare(<<<'SQL'
            INSERT INTO local_authorization_list (charger_id, version_number, entries)
            VALUES (:charger_id, :version_number, :entries)
            ON CONFLICT (charger_id) DO UPDATE SET
                version_number = excluded.version_number,
                entries = excluded.entries
            SQL);
        $statement->execute([
            'charger_id' => $chargerId,
            'version_number' => $versionNumber,
            'entries' => json_encode($entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
        $this->database->commit();

        return 'Accepted';
    }
}
