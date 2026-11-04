<?php

declare(strict_types=1);

namespace ChargerEmulator\Storage;

use PDO;

/**
 * Opens the local SQLite database and creates the emulator's tables.
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
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS charger (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                station_identity TEXT NOT NULL,
                csms_url TEXT NOT NULL,
                vendor_name TEXT NOT NULL,
                model TEXT NOT NULL,
                serial_number TEXT NOT NULL DEFAULT '',
                boot_status TEXT NOT NULL DEFAULT 'Not connected',
                updated_at TEXT NOT NULL
            )
            SQL);
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS exchange_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                direction TEXT NOT NULL,
                action TEXT NOT NULL,
                message TEXT NOT NULL,
                created_at TEXT NOT NULL
            )
            SQL);

        return $pdo;
    }
}
