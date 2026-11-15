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
use ChargerEmulator\Storage\FirmwareUpdateRepository;
use ChargerEmulator\Domain\FirmwareUpdateManager;
use ChargerEmulator\Domain\CertificateManagementManager;
use ChargerEmulator\Storage\OcppCertificateRepository;

require __DIR__ . '/../src/autoload.php';

$dataPath = getenv('DATA_PATH') ?: dirname(__DIR__) . '/data/emulator.sqlite';
$database = Database::connect($dataPath);
$outletSessions = new OutletSessionRepository($database);
$certificateStore = new OcppCertificateRepository($database);
$firmwareUpdates = new FirmwareUpdateManager(
    new FirmwareUpdateRepository($database),
    dirname($dataPath) . '/firmware',
    getenv('FIRMWARE_SIGNING_ROOTS_PATH') ?: dirname($dataPath) . '/firmware-signing-roots.pem',
    $certificateStore,
);
$certificateManagement = new CertificateManagementManager($certificateStore);
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
    $firmwareUpdates,
    $certificateManagement,
);
$worker->run();
