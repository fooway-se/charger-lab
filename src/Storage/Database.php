<?php

declare(strict_types=1);

namespace ChargerEmulator\Storage;

use ChargerEmulator\Protocol\OcppConnectionUrl;
use PDO;

/**
 * Opens the local SQLite database and migrates persisted emulator data.
 */
final class Database
{
    public static function connect(string $path): PDO
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException("Unable to create database directory: {$directory}");
        }

        $pdo = new PDO('sqlite:' . $path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->beginTransaction();

        try {
            $csmsModeAdded = self::migrateChargers($pdo);
            self::migrateWorkspaceSettings($pdo, $csmsModeAdded);
            self::migrateLogs($pdo);
            $pdo->exec('CREATE INDEX IF NOT EXISTS exchange_log_charger_id ON exchange_log (charger_id, id)');
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }

        return $pdo;
    }

    private static function migrateChargers(PDO $pdo): bool
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS charger (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                station_identity TEXT NOT NULL UNIQUE,
                csms_url TEXT NOT NULL,
                vendor_name TEXT NOT NULL,
                model TEXT NOT NULL,
                serial_number TEXT NOT NULL DEFAULT '',
                outlet_count INTEGER NOT NULL DEFAULT 1 CHECK (outlet_count BETWEEN 1 AND 16),
                outlet_topology TEXT NOT NULL DEFAULT 'independent' CHECK (outlet_topology IN ('independent', 'shared')),
                uses_default_csms INTEGER NOT NULL DEFAULT 1 CHECK (uses_default_csms IN (0, 1)),
                boot_status TEXT NOT NULL DEFAULT 'Not connected',
                updated_at TEXT NOT NULL
            )
            SQL);

        $columns = $pdo->query('PRAGMA table_info(charger)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('outlet_count', $columns, true)) {
            $pdo->exec('ALTER TABLE charger ADD COLUMN outlet_count INTEGER NOT NULL DEFAULT 1');
        }

        if (!in_array('outlet_topology', $columns, true)) {
            $pdo->exec("ALTER TABLE charger ADD COLUMN outlet_topology TEXT NOT NULL DEFAULT 'independent'");
        }

        $csmsModeAdded = !in_array('uses_default_csms', $columns, true);
        if ($csmsModeAdded) {
            $pdo->exec('ALTER TABLE charger ADD COLUMN uses_default_csms INTEGER NOT NULL DEFAULT 1');
        }

        $schema = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'charger'")->fetchColumn();
        if (is_string($schema) && preg_match('/CHECK\s*\(\s*id\s*=\s*1\s*\)/i', $schema) === 1) {
            $pdo->exec(<<<'SQL'
                CREATE TABLE charger_multi (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    station_identity TEXT NOT NULL UNIQUE,
                    csms_url TEXT NOT NULL,
                    vendor_name TEXT NOT NULL,
                    model TEXT NOT NULL,
                    serial_number TEXT NOT NULL DEFAULT '',
                    outlet_count INTEGER NOT NULL DEFAULT 1 CHECK (outlet_count BETWEEN 1 AND 16),
                    outlet_topology TEXT NOT NULL DEFAULT 'independent' CHECK (outlet_topology IN ('independent', 'shared')),
                    uses_default_csms INTEGER NOT NULL DEFAULT 1 CHECK (uses_default_csms IN (0, 1)),
                    boot_status TEXT NOT NULL DEFAULT 'Not connected',
                    updated_at TEXT NOT NULL
                )
                SQL);
            $pdo->exec('INSERT INTO charger_multi SELECT id, station_identity, csms_url, vendor_name, model, serial_number, outlet_count, outlet_topology, uses_default_csms, boot_status, updated_at FROM charger');
            $pdo->exec('DROP TABLE charger');
            $pdo->exec('ALTER TABLE charger_multi RENAME TO charger');
        }

        return $csmsModeAdded;
    }

    private static function migrateWorkspaceSettings(PDO $pdo, bool $csmsModeAdded): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS workspace_settings (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                default_csms_url TEXT NOT NULL
            )
            SQL);

        $chargerRows = $pdo->query('SELECT station_identity, csms_url FROM charger ORDER BY id')->fetchAll();
        $storedUrl = $pdo->query('SELECT default_csms_url FROM workspace_settings WHERE id = 1')->fetchColumn();
        if ($storedUrl === false) {
            $firstCharger = $chargerRows[0] ?? null;
            $storedUrl = is_array($firstCharger)
                ? OcppConnectionUrl::endpointFromConnectionUrl($firstCharger['csms_url'], $firstCharger['station_identity'])
                : 'ws://fake-csms:9000/ocpp';
            $statement = $pdo->prepare('INSERT INTO workspace_settings (id, default_csms_url) VALUES (1, :url)');
            $statement->execute(['url' => $storedUrl]);
        }

        if ($csmsModeAdded) {
            $statement = $pdo->prepare('UPDATE charger SET csms_url = :url, uses_default_csms = :uses_default WHERE station_identity = :identity');
            foreach ($chargerRows as $charger) {
                $endpointUrl = OcppConnectionUrl::endpointFromConnectionUrl($charger['csms_url'], $charger['station_identity']);
                $statement->execute([
                    'url' => $endpointUrl,
                    'uses_default' => $endpointUrl === $storedUrl ? 1 : 0,
                    'identity' => $charger['station_identity'],
                ]);
            }
        }
    }

    private static function migrateLogs(PDO $pdo): void
    {
        $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'exchange_log'")->fetchColumn();
        if ($exists === false) {
            $pdo->exec(<<<'SQL'
                CREATE TABLE exchange_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    charger_id INTEGER NOT NULL REFERENCES charger(id) ON DELETE CASCADE,
                    direction TEXT NOT NULL,
                    action TEXT NOT NULL,
                    message TEXT NOT NULL,
                    created_at TEXT NOT NULL
                )
                SQL);

            return;
        }

        $columns = $pdo->query('PRAGMA table_info(exchange_log)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('charger_id', $columns, true)) {
            $pdo->exec(<<<'SQL'
                CREATE TABLE exchange_log_multi (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    charger_id INTEGER NOT NULL REFERENCES charger(id) ON DELETE CASCADE,
                    direction TEXT NOT NULL,
                    action TEXT NOT NULL,
                    message TEXT NOT NULL,
                    created_at TEXT NOT NULL
                )
                SQL);
            $chargerExists = $pdo->query('SELECT 1 FROM charger WHERE id = 1')->fetchColumn() !== false;
            if ($chargerExists) {
                $pdo->exec('INSERT INTO exchange_log_multi (id, charger_id, direction, action, message, created_at) SELECT id, 1, direction, action, message, created_at FROM exchange_log');
            }

            $pdo->exec('DROP TABLE exchange_log');
            $pdo->exec('ALTER TABLE exchange_log_multi RENAME TO exchange_log');
        }
    }
}
