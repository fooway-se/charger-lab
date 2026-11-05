<?php

declare(strict_types=1);

use ChargerEmulator\Controller\StationController;
use ChargerEmulator\Http\Request;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\AvailabilityRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\ExchangeRepository;
use ChargerEmulator\Storage\OcppCommandRepository;
use ChargerEmulator\Storage\OutletSessionRepository;
use ChargerEmulator\Storage\ResetRepository;
use ChargerEmulator\Storage\WorkspaceSettingsRepository;

it('does not test a second WebSocket while a charger is already connected', function (): void {
    $database = Database::connect(':memory:');
    $chargers = new ChargerRepository($database);
    $chargerId = $chargers->createDefault();
    $chargers->setConnectionStatus($chargerId, 'Connected');
    $controller = new StationController(
        $chargers,
        new ExchangeRepository($database),
        new WorkspaceSettingsRepository($database),
        new OcppCommandRepository($database),
        new OutletSessionRepository($database),
        new AvailabilityRepository($database),
        new ResetRepository($database, new OutletSessionRepository($database)),
    );

    $response = $controller->testConnection(new Request('POST', '/api/connection/test', [], [], [
        'charger_id' => $chargerId,
    ]));

    expect($response->status)->toBe(409)
        ->and($response->body)->toContain('already connected');
});
