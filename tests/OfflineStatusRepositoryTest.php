<?php

declare(strict_types=1);

use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\OfflineStatusRepository;

it('preserves offline duration and changed connector state across database reopen', function (): void {
    $directory = sys_get_temp_dir() . '/charger-offline-state-' . bin2hex(random_bytes(6));
    mkdir($directory, 0775, true);
    $databasePath = $directory . '/emulator.sqlite';

    try {
        $database = Database::connect($databasePath);
        $chargerId = (new ChargerRepository($database))->createDefault();
        $offlineStatuses = new OfflineStatusRepository($database);
        $offlineStatuses->begin($chargerId, 1234.5);
        $offlineStatuses->markConnectorChanged($chargerId, 2, 1);
        unset($offlineStatuses, $database);

        $database = Database::connect($databasePath);
        $offlineStatuses = new OfflineStatusRepository($database);

        expect($offlineStatuses->pending($chargerId))->toBe([
            'offline_since' => 1234.5,
            'changed_connectors' => [['evse_id' => 2, 'connector_id' => 1]],
        ]);

        $offlineStatuses->clear($chargerId);
        expect($offlineStatuses->pending($chargerId))->toBeNull();
    } finally {
        foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
    }
});
