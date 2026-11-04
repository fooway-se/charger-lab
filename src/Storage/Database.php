<?php

declare(strict_types=1);

namespace ChargerEmulator\Storage;

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
            self::migrateChargers($pdo);
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

    private static function migrateChargers(PDO $pdo): void
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
                    boot_status TEXT NOT NULL DEFAULT 'Not connected',
                    updated_at TEXT NOT NULL
                )
                SQL);
            $pdo->exec('INSERT INTO charger_multi SELECT id, station_identity, csms_url, vendor_name, model, serial_number, outlet_count, outlet_topology, boot_status, updated_at FROM charger');
            $pdo->exec('DROP TABLE charger');
            $pdo->exec('ALTER TABLE charger_multi RENAME TO charger');
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
