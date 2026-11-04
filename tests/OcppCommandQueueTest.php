<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\OcppCommandRepository;

$directory = sys_get_temp_dir() . '/charger-commands-' . bin2hex(random_bytes(6));
mkdir($directory, 0775, true);
$databasePath = $directory . '/emulator.sqlite';
$database = Database::connect($databasePath);
$charger = new ChargerRepository($database)->all()[0];
$commands = new OcppCommandRepository($database);
$commandId = $commands->enqueue((int) $charger['id'], 'BootNotification', ['reason' => 'PowerUp']);
$claimed = $commands->claimNext();
$commands->complete($commandId, ['status' => 'Accepted']);
$completed = $commands->get($commandId);
if (($claimed['id'] ?? null) !== $commandId
    || ($claimed['action'] ?? null) !== 'BootNotification'
    || ($claimed['payload']['reason'] ?? null) !== 'PowerUp'
    || ($completed['status'] ?? null) !== 'completed'
    || ($completed['result']['status'] ?? null) !== 'Accepted') {
    throw new RuntimeException('A worker should claim charger commands and return their completed OCPP result to the HTTP request.');
}

$replayBootId = $commands->enqueue((int) $charger['id'], 'BootNotification', ['reason' => 'PowerUp']);
$commands->claimNext();
$interruptedAuthorizeId = $commands->enqueue((int) $charger['id'], 'Authorize', ['idToken' => ['idToken' => 'TAG-1', 'type' => 'ISO14443']]);
$commands->claimNext();
$commands->recoverInterrupted();
$recoveredBoot = $commands->get($replayBootId);
$failedAuthorize = $commands->get($interruptedAuthorizeId);
if (($recoveredBoot['status'] ?? null) !== 'pending'
    || ($failedAuthorize['status'] ?? null) !== 'failed') {
    throw new RuntimeException('A restarted worker should retry BootNotification and fail interrupted non-idempotent actions clearly.');
}

unset($commands, $database);
foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
    if (is_file($path)) {
        unlink($path);
    }
}
rmdir($directory);

echo "OCPP command queue: PASS\n";
