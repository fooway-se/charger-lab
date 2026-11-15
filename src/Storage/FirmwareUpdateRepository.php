<?php

declare(strict_types=1);

namespace ChargerEmulator\Storage;

use JsonException;
use PDO;
use RuntimeException;

/** Persists the simulated firmware image and update lifecycle for a charger. */
final class FirmwareUpdateRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function current(int $chargerId): array
    {
        $this->ensureRow($chargerId);
        $statement = $this->database->prepare('SELECT * FROM firmware_state WHERE charger_id = :charger_id');
        $statement->execute(['charger_id' => $chargerId]);
        $state = $statement->fetch();
        if (!is_array($state)) {
            throw new RuntimeException('Could not load firmware state for the charger.');
        }

        try {
            $state['firmware_request'] = $state['firmware_request'] === null
                ? null
                : json_decode($state['firmware_request'], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The stored firmware request is invalid JSON.', previous: $exception);
        }

        return $state;
    }

    public function firmwareVersion(int $chargerId): string
    {
        return (string) $this->current($chargerId)['firmware_version'];
    }

    public function schedule(
        int $chargerId,
        int $requestId,
        array $firmwareRequest,
        int $retrieveAt,
        ?int $installAt,
        int $retries,
        int $retryInterval,
    ): bool {
        $current = $this->current($chargerId);
        if (in_array($current['state'], [
            'DownloadScheduled', 'Downloading', 'Downloaded', 'InstallScheduled', 'Installing', 'InstallRebooting',
        ], true)) {
            return false;
        }

        $statement = $this->database->prepare(<<<'SQL'
            UPDATE firmware_state SET request_id = :request_id, firmware_request = :firmware_request,
                state = 'DownloadScheduled', retrieve_at = :retrieve_at, install_at = :install_at,
                next_action_at = :next_action_at, retries_left = :retries_left,
                retry_interval = :retry_interval, download_attempts = 0, package_path = NULL,
                package_sha256 = NULL, package_size = NULL, simulated_version = NULL,
                last_error = NULL, updated_at = :updated_at
            WHERE charger_id = :charger_id
            SQL);
        $statement->execute([
            'request_id' => $requestId,
            'firmware_request' => json_encode($firmwareRequest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'retrieve_at' => $retrieveAt,
            'install_at' => $installAt,
            'next_action_at' => $retrieveAt,
            'retries_left' => $retries,
            'retry_interval' => $retryInterval,
            'updated_at' => gmdate(DATE_ATOM),
            'charger_id' => $chargerId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function beginDownload(int $chargerId): void
    {
        $this->update($chargerId, [
            'state' => 'Downloading',
            'next_action_at' => null,
            'download_attempts' => 'download_attempts + 1',
            'last_error' => null,
        ]);
    }

    public function recordDownloadedPackage(int $chargerId, string $path, string $sha256, int $size): void
    {
        $this->update($chargerId, [
            'state' => 'Downloaded',
            'package_path' => $path,
            'package_sha256' => $sha256,
            'package_size' => $size,
            'next_action_at' => time(),
            'last_error' => null,
        ]);
    }

    public function scheduleInstall(int $chargerId, int $installAt): void
    {
        $this->update($chargerId, [
            'state' => 'InstallScheduled',
            'next_action_at' => $installAt,
        ]);
    }

    public function beginInstall(int $chargerId, int $nextActionAt): void
    {
        $this->update($chargerId, [
            'state' => 'Installing',
            'next_action_at' => $nextActionAt,
        ]);
    }

    public function prepareReboot(int $chargerId, string $simulatedVersion): void
    {
        $this->update($chargerId, [
            'state' => 'InstallRebooting',
            'firmware_version' => $simulatedVersion,
            'simulated_version' => $simulatedVersion,
            'next_action_at' => null,
        ]);
    }

    public function complete(int $chargerId): void
    {
        $this->update($chargerId, [
            'state' => 'Installed',
            'next_action_at' => null,
            'last_error' => null,
        ]);
    }

    public function fail(int $chargerId, string $state, string $reason): void
    {
        $this->update($chargerId, [
            'state' => $state,
            'next_action_at' => null,
            'last_error' => substr($reason, 0, 500),
        ]);
    }

    public function retryDownload(int $chargerId): bool
    {
        $current = $this->current($chargerId);
        if ((int) $current['retries_left'] < 1) {
            return false;
        }

        $this->update($chargerId, [
            'state' => 'DownloadScheduled',
            'next_action_at' => time() + (int) $current['retry_interval'],
            'retries_left' => 'retries_left - 1',
        ]);

        return true;
    }

    public function recoverInterrupted(): void
    {
        $this->database->exec(<<<'SQL'
            UPDATE firmware_state
            SET state = 'DownloadScheduled', next_action_at = strftime('%s', 'now'),
                updated_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now')
            WHERE state = 'Downloading'
            SQL);
        $this->database->exec(<<<'SQL'
            UPDATE firmware_state
            SET state = 'InstallScheduled', next_action_at = strftime('%s', 'now'),
                updated_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now')
            WHERE state = 'Installing'
            SQL);
    }

    private function ensureRow(int $chargerId): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            INSERT OR IGNORE INTO firmware_state (charger_id, firmware_version, updated_at)
            VALUES (:charger_id, '1.0.0', :updated_at)
            SQL);
        $statement->execute(['charger_id' => $chargerId, 'updated_at' => gmdate(DATE_ATOM)]);
    }

    /** @param array<string, scalar|null> $fields */
    private function update(int $chargerId, array $fields): void
    {
        $assignments = [];
        $parameters = ['charger_id' => $chargerId, 'updated_at' => gmdate(DATE_ATOM)];
        foreach ($fields as $name => $value) {
            if ($name === 'download_attempts' || $name === 'retries_left') {
                $assignments[] = $name . ' = ' . $value;
                continue;
            }

            $assignments[] = $name . ' = :' . $name;
            $parameters[$name] = $value;
        }
        $assignments[] = 'updated_at = :updated_at';
        $statement = $this->database->prepare(
            'UPDATE firmware_state SET ' . implode(', ', $assignments) . ' WHERE charger_id = :charger_id',
        );
        $statement->execute($parameters);
        if ($statement->rowCount() === 0) {
            $this->ensureRow($chargerId);
            $statement->execute($parameters);
        }
    }
}
