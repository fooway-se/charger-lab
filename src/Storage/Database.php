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
            self::migrateCommands($pdo);
            self::migrateOutletSessions($pdo);
            self::migrateDeviceModelSettings($pdo);
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
                connection_status TEXT NOT NULL DEFAULT 'Not connected',
                boot_status TEXT NOT NULL DEFAULT 'Not connected',
                boot_retry_at TEXT,
                next_transaction_sequence INTEGER NOT NULL DEFAULT 1 CHECK (next_transaction_sequence > 0),
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

        if (!in_array('connection_status', $columns, true)) {
            $pdo->exec("ALTER TABLE charger ADD COLUMN connection_status TEXT NOT NULL DEFAULT 'Not connected'");
        }

        if (!in_array('boot_retry_at', $columns, true)) {
            $pdo->exec('ALTER TABLE charger ADD COLUMN boot_retry_at TEXT');
        }

        if (!in_array('next_transaction_sequence', $columns, true)) {
            $pdo->exec('ALTER TABLE charger ADD COLUMN next_transaction_sequence INTEGER NOT NULL DEFAULT 1');
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
                    connection_status TEXT NOT NULL DEFAULT 'Not connected',
                    boot_status TEXT NOT NULL DEFAULT 'Not connected',
                    boot_retry_at TEXT,
                    next_transaction_sequence INTEGER NOT NULL DEFAULT 1 CHECK (next_transaction_sequence > 0),
                    updated_at TEXT NOT NULL
                )
                SQL);
            $pdo->exec('INSERT INTO charger_multi SELECT id, station_identity, csms_url, vendor_name, model, serial_number, outlet_count, outlet_topology, uses_default_csms, connection_status, boot_status, boot_retry_at, next_transaction_sequence, updated_at FROM charger');
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

    private static function migrateCommands(PDO $pdo): void
    {
        $schema = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'ocpp_command'")->fetchColumn();
        $needsMigration = is_string($schema)
            && (!str_contains($schema, "'RemoteStart'") || !str_contains($schema, "'RemoteStop'"));
        if ($needsMigration) {
            $pdo->exec('DROP INDEX IF EXISTS ocpp_command_pending');
            $pdo->exec('ALTER TABLE ocpp_command RENAME TO ocpp_command_legacy');
        }

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS ocpp_command (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                charger_id INTEGER NOT NULL REFERENCES charger(id) ON DELETE CASCADE,
                action TEXT NOT NULL CHECK (action IN ('BootNotification', 'Authorize', 'Disconnect', 'PlugIn', 'Unplug', 'RemoteStart', 'RemoteStop')),
                payload TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'processing', 'completed', 'failed')),
                result TEXT,
                error TEXT,
                created_at TEXT NOT NULL,
                completed_at TEXT
            )
            SQL);
        if ($needsMigration) {
            $pdo->exec(<<<'SQL'
                INSERT INTO ocpp_command (id, charger_id, action, payload, status, result, error, created_at, completed_at)
                SELECT id, charger_id, action, payload, status, result, error, created_at, completed_at
                FROM ocpp_command_legacy
                SQL);
            $pdo->exec('DROP TABLE ocpp_command_legacy');
        }

        $pdo->exec('CREATE INDEX IF NOT EXISTS ocpp_command_pending ON ocpp_command (status, id)');
    }

    private static function migrateOutletSessions(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS outlet_session (
                charger_id INTEGER NOT NULL REFERENCES charger(id) ON DELETE CASCADE,
                evse_id INTEGER NOT NULL CHECK (evse_id > 0),
                connector_id INTEGER NOT NULL CHECK (connector_id > 0),
                connector_status TEXT NOT NULL CHECK (connector_status IN ('Available', 'Occupied')),
                transaction_id TEXT,
                id_token TEXT,
                seq_no INTEGER NOT NULL DEFAULT 0 CHECK (seq_no >= 0),
                meter_wh INTEGER NOT NULL DEFAULT 0 CHECK (meter_wh >= 0),
                stop_reason TEXT CHECK (stop_reason IS NULL OR stop_reason = 'Remote'),
                meter_updated_at TEXT,
                PRIMARY KEY (charger_id, evse_id, connector_id),
                UNIQUE (charger_id, transaction_id),
                CHECK ((connector_status = 'Available' AND transaction_id IS NULL AND id_token IS NULL)
                    OR (connector_status = 'Occupied' AND transaction_id IS NOT NULL))
            )
            SQL);

        $columns = $pdo->query('PRAGMA table_info(outlet_session)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('meter_wh', $columns, true)) {
            $pdo->exec('ALTER TABLE outlet_session ADD COLUMN meter_wh INTEGER NOT NULL DEFAULT 0 CHECK (meter_wh >= 0)');
        }

        if (!in_array('stop_reason', $columns, true)) {
            $pdo->exec("ALTER TABLE outlet_session ADD COLUMN stop_reason TEXT CHECK (stop_reason IS NULL OR stop_reason = 'Remote')");
        }

        if (!in_array('meter_updated_at', $columns, true)) {
            $pdo->exec('ALTER TABLE outlet_session ADD COLUMN meter_updated_at TEXT');
        }
    }

    private static function migrateDeviceModelSettings(PDO $pdo): void
    {
        $schema = $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'device_model_setting'")->fetchColumn();
        if ($schema !== false && str_contains((string) $schema, "'TxStartedMeasurands'")) {
            return;
        }

        if ($schema !== false) {
            $pdo->exec('ALTER TABLE device_model_setting RENAME TO device_model_setting_legacy');
        }

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS device_model_setting (
                charger_id INTEGER NOT NULL REFERENCES charger(id) ON DELETE CASCADE,
                variable TEXT NOT NULL CHECK (variable IN ('TxStartedMeasurands', 'TxUpdatedInterval', 'TxUpdatedMeasurands')),
                value TEXT NOT NULL,
                PRIMARY KEY (charger_id, variable)
            )
            SQL);

        if ($schema !== false) {
            $pdo->exec('INSERT INTO device_model_setting (charger_id, variable, value) SELECT charger_id, variable, value FROM device_model_setting_legacy');
            $pdo->exec('DROP TABLE device_model_setting_legacy');
        }
    }
}
