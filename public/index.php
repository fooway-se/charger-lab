<?php

declare(strict_types=1);

/**
 * Builds HTTP routes for the local charger emulator API.
 */
use ChargerEmulator\Controller\StationController;
use ChargerEmulator\Http\Request;
use ChargerEmulator\Http\Response;
use ChargerEmulator\Http\Router;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\AvailabilityRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\ExchangeRepository;
use ChargerEmulator\Storage\OcppCommandRepository;
use ChargerEmulator\Storage\OutletSessionRepository;
use ChargerEmulator\Storage\WorkspaceSettingsRepository;

require __DIR__ . '/../src/autoload.php';

try {
    $request = Request::fromGlobals();
} catch (InvalidArgumentException $exception) {
    Response::json(400, ['error' => $exception->getMessage()])->send();
}

try {
    $database = Database::connect(getenv('DATA_PATH') ?: dirname(__DIR__) . '/data/emulator.sqlite');
    $controller = new StationController(
        new ChargerRepository($database),
        new ExchangeRepository($database),
        new WorkspaceSettingsRepository($database),
        new OcppCommandRepository($database),
        new OutletSessionRepository($database),
        new AvailabilityRepository($database),
    );
    $router = new Router();
    $router->add('GET', '/api/state', [$controller, 'state']);
    $router->add('POST', '/api/settings/csms', [$controller, 'saveWorkspaceSettings']);
    $router->add('POST', '/api/settings/tls', [$controller, 'saveTlsSettings']);
    $router->add('POST', '/api/connection/test', [$controller, 'testConnection']);
    $router->add('POST', '/api/charger/create', [$controller, 'create']);
    $router->add('POST', '/api/charger', [$controller, 'save']);
    $router->add('POST', '/api/charger/delete', [$controller, 'delete']);
    $router->add('POST', '/api/boot', [$controller, 'boot']);
    $router->add('POST', '/api/authorize', [$controller, 'authorize']);
    $router->add('POST', '/api/outlet/plug-in', [$controller, 'plugIn']);
    $router->add('POST', '/api/outlet/unplug', [$controller, 'unplug']);
    $router->add('POST', '/api/log/clear', [$controller, 'clearLog']);

    $router->dispatch($request)->send();
} catch (Throwable $exception) {
    Response::json(500, ['error' => 'The local emulator encountered an internal error.'])->send();
}
