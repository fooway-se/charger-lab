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

    public function recent(int $chargerId, int $limit = 100): array
    {
        $statement = $this->database->prepare('SELECT * FROM exchange_log WHERE charger_id = :charger_id ORDER BY id DESC LIMIT :limit');
        $statement->bindValue(':charger_id', $chargerId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_reverse($statement->fetchAll());
    }

    /** @return list<array{direction:string,action:string,message:string,created_at:string}> */
    public function forLogUpload(int $chargerId, string $logType, ?string $oldestTimestamp, ?string $latestTimestamp): array
    {
        $statement = $this->database->prepare(
            'SELECT direction, action, message, created_at FROM exchange_log WHERE charger_id = :charger_id ORDER BY id',
        );
        $statement->execute(['charger_id' => $chargerId]);

        $oldest = $oldestTimestamp === null ? null : strtotime($oldestTimestamp);
        $latest = $latestTimestamp === null ? null : strtotime($latestTimestamp);
        $securityActions = ['SecurityEventNotification', 'InstallCertificate', 'DeleteCertificate', 'GetInstalledCertificateIds', 'UpdateFirmware'];
        $entries = [];
        foreach ($statement->fetchAll() as $entry) {
            $createdAt = strtotime($entry['created_at']);
            if ($createdAt === false || ($oldest !== null && $createdAt < $oldest) || ($latest !== null && $createdAt > $latest)) {
                continue;
            }
            if ($logType === 'SecurityLog' && !in_array($entry['action'], $securityActions, true)) {
                continue;
            }

            $message = $entry['message'];
            try {
                $decoded = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
                $this->redactIdTokens($decoded);
                $message = json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (\JsonException) {
                // System log messages are plain text; they contain no OCPP token objects to redact.
            }

            $entries[] = [
                'direction' => $entry['direction'],
                'action' => $entry['action'],
                'message' => $message,
                'timestamp' => $entry['created_at'],
            ];
        }

        return $entries;
    }

    private function redactIdTokens(mixed &$value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        $redacted = false;
        foreach ($value as $key => &$child) {
            if ($key === 'idToken' && is_string($child)) {
                $child = '[REDACTED]';
                $redacted = true;
                continue;
            }

            if ($key === 'data' && is_string($child)) {
                try {
                    $decodedData = json_decode($child, true, flags: JSON_THROW_ON_ERROR);
                    if (is_array($decodedData) && $this->redactIdTokens($decodedData)) {
                        $child = json_encode($decodedData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                        $redacted = true;
                    }
                } catch (\JsonException) {
                    // Customer information may be plain text rather than JSON.
                }
                continue;
            }

            $redacted = $this->redactIdTokens($child) || $redacted;
        }

        return $redacted;
    }
}
