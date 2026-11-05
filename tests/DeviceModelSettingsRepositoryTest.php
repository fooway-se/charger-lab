<?php

declare(strict_types=1);

use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\DeviceModelSettingsRepository;

it('reads integer settings with a fallback and minimum', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $settings = new DeviceModelSettingsRepository($database);

    expect($settings->getInteger($chargerId, 'EVConnectionTimeOut', 30, 1))->toBe(30);

    $settings->set($chargerId, 'EVConnectionTimeOut', '0');
    expect($settings->getInteger($chargerId, 'EVConnectionTimeOut', 30, 1))->toBe(30);

    $settings->set($chargerId, 'EVConnectionTimeOut', '12');
    expect($settings->getInteger($chargerId, 'EVConnectionTimeOut', 30, 1))->toBe(12);
});
