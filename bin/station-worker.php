<?php

declare(strict_types=1);

/**
 * Runs the persistent OCPP sessions used by the local charger emulator.
 */
use ChargerEmulator\Protocol\StationSessionWorker;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\AvailabilityRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\DeviceModelSettingsRepository;
use ChargerEmulator\Storage\ExchangeRepository;
use ChargerEmulator\Storage\OcppCommandRepository;
use ChargerEmulator\Storage\OutletSessionRepository;
use ChargerEmulator\Storage\ResetRepository;
use ChargerEmulator\Storage\TransactionEventOutboxRepository;
use ChargerEmulator\Storage\WorkspaceSettingsRepository;
use ChargerEmulator\Storage\NetworkProfileRepository;
use ChargerEmulator\Storage\LocalAuthorizationListRepository;

require __DIR__ . '/../src/autoload.php';

$database = Database::connect(getenv('DATA_PATH') ?: dirname(__DIR__) . '/data/emulator.sqlite');
$outletSessions = new OutletSessionRepository($database);
$worker = new StationSessionWorker(
    new ChargerRepository($database),
    new ExchangeRepository($database),
    new OcppCommandRepository($database),
    $outletSessions,
    new AvailabilityRepository($database),
    new ResetRepository($database, $outletSessions),
    new DeviceModelSettingsRepository($database),
    new TransactionEventOutboxRepository($database),
    new WorkspaceSettingsRepository($database),
    new NetworkProfileRepository($database),
    new LocalAuthorizationListRepository($database),
);
$worker->run();
