<?php

declare(strict_types=1);

use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\OcppCommandRepository;

it('migrates queued OCPP commands safely', function (): void {
    $directory = sys_get_temp_dir() . '/charger-command-migration-' . bin2hex(random_bytes(6));

    mkdir($directory, 0775, true);

    $databasePath = $directory . '/emulator.sqlite';

    $database = Database::connect($databasePath);

    $charger = (new ChargerRepository($database))->all()[0];

    $database->exec('DROP INDEX ocpp_command_pending');

    $database->exec('DROP TABLE ocpp_command');

    $database->exec(<<<'SQL'
        CREATE TABLE ocpp_command (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            charger_id INTEGER NOT NULL REFERENCES charger(id) ON DELETE CASCADE,
            action TEXT NOT NULL CHECK (action IN ('BootNotification', 'Authorize', 'Disconnect')),
            payload TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'processing', 'completed', 'failed')),
            result TEXT,
            error TEXT,
            created_at TEXT NOT NULL,
            completed_at TEXT
        )
        SQL);

    $database->prepare(<<<'SQL'
        INSERT INTO ocpp_command (id, charger_id, action, payload, status, result, created_at)
        VALUES (7, :charger_id, 'Authorize', '{"idToken":{"idToken":"OLD","type":"ISO14443"}}', 'completed', '{"idTokenInfo":{"status":"Accepted"}}', '2026-11-04T00:00:00Z')
        SQL)->execute(['charger_id' => $charger['id']]);

    unset($database);

    $database = Database::connect($databasePath);

    $commands = new OcppCommandRepository($database);

    $legacyCommand = $commands->get(7);

    $plugCommandId = $commands->enqueue((int) $charger['id'], 'PlugIn', ['evseId' => 1, 'connectorId' => 1]);

    $plugCommand = $commands->get($plugCommandId);
    $localStopCommandId = $commands->enqueue((int) $charger['id'], 'LocalStop', [
        'idToken' => ['idToken' => 'OLD', 'type' => 'ISO14443'],
    ]);
    $localStopCommand = $commands->get($localStopCommandId);
    $chargingStateCommandId = $commands->enqueue((int) $charger['id'], 'SetChargingState', [
        'evseId' => 1,
        'connectorId' => 1,
        'chargingState' => 'SuspendedEV',
    ]);
    $chargingStateCommand = $commands->get($chargingStateCommandId);
    $coldBootCommandId = $commands->enqueue((int) $charger['id'], 'ColdBoot', []);
    $coldBootCommand = $commands->get($coldBootCommandId);

    $schema = (string) $database->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'ocpp_command'")->fetchColumn();

    expect(!str_contains($schema, "'PlugIn'")
        || !str_contains($schema, "'LocalStop'")
        || !str_contains($schema, "'SetChargingState'")
        || !str_contains($schema, "'ColdBoot'")
        || ($legacyCommand['action'] ?? null) !== 'Authorize'
        || ($legacyCommand['result']['idTokenInfo']['status'] ?? null) !== 'Accepted'
        || ($plugCommand['action'] ?? null) !== 'PlugIn'
        || ($plugCommand['payload']['evseId'] ?? null) !== 1
        || ($localStopCommand['action'] ?? null) !== 'LocalStop'
        || ($chargingStateCommand['payload']['chargingState'] ?? null) !== 'SuspendedEV'
        || ($coldBootCommand['action'] ?? null) !== 'ColdBoot')->toBeFalse('The command migration should preserve existing results and admit outlet simulation actions.');

    unset($commands, $database);

    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    rmdir($directory);

});
