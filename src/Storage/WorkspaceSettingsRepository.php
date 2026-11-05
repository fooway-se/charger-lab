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

    public function getTlsSettings(): array
    {
        $statement = $this->database->query('SELECT tls_server_name, tls_ca_certificate FROM workspace_settings WHERE id = 1');
        $settings = $statement->fetch();

        if (!is_array($settings)) {
            return ['tls_server_name' => '', 'tls_ca_certificate' => ''];
        }

        return [
            'tls_server_name' => (string) $settings['tls_server_name'],
            'tls_ca_certificate' => (string) $settings['tls_ca_certificate'],
        ];
    }

    public function saveTlsSettings(string $serverName, string $caCertificate): void
    {
        $statement = $this->database->prepare(<<<'SQL'
            UPDATE workspace_settings
            SET tls_server_name = :tls_server_name, tls_ca_certificate = :tls_ca_certificate
            WHERE id = 1
            SQL);
        $statement->execute([
            'tls_server_name' => $serverName,
            'tls_ca_certificate' => $caCertificate,
        ]);
    }
}
