<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use ChargerEmulator\Http\Request;
use ChargerEmulator\Http\Response;
use ChargerEmulator\Http\Router;

$router = new Router();
$router->add('POST', '/probe', static function (Request $request): Response {
    return Response::json(202, ['received' => $request->form['value'] ?? null]);
});

$response = $router->dispatch(new Request('POST', '/probe', [], ['value' => 'ready']));
$decoded = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
if ($response->status !== 202 || ($decoded['received'] ?? null) !== 'ready') {
    throw new RuntimeException('The router should dispatch the matching request and return its response.');
}
if (($response->headers['Content-Type'] ?? null) !== 'application/json; charset=utf-8') {
    throw new RuntimeException('JSON responses should carry the JSON content type.');
}

$missing = $router->dispatch(new Request('GET', '/missing', [], []));
if ($missing->status !== 404) {
    throw new RuntimeException('The router should return 404 when no route matches.');
}

echo "HTTP request/router/response: PASS\n";
