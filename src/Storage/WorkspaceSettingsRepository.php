<?php

declare(strict_types=1);

namespace ChargerEmulator\Storage;

use PDO;

/**
 * Persists workspace-wide defaults used by charging stations.
 */
final class WorkspaceSettingsRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function getCsmsUrl(): string
    {
        return (string) $this->database->query('SELECT default_csms_url FROM workspace_settings WHERE id = 1')->fetchColumn();
    }

    public function saveCsmsUrl(string $url): void
    {
        $statement = $this->database->prepare('UPDATE workspace_settings SET default_csms_url = :url WHERE id = 1');
        $statement->execute(['url' => $url]);
    }
}
