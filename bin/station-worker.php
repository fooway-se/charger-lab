<?php

declare(strict_types=1);

/**
 * Runs the persistent OCPP sessions used by the local charger emulator.
 */
use ChargerEmulator\Protocol\StationSessionWorker;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\ExchangeRepository;
use ChargerEmulator\Storage\OcppCommandRepository;

require __DIR__ . '/../src/autoload.php';

$database = Database::connect(getenv('DATA_PATH') ?: dirname(__DIR__) . '/data/emulator.sqlite');
$worker = new StationSessionWorker(
    new ChargerRepository($database),
    new ExchangeRepository($database),
    new OcppCommandRepository($database),
);
$worker->run();
