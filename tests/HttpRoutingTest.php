<?php

declare(strict_types=1);

use ChargerEmulator\Http\Request;
use ChargerEmulator\Http\Response;
use ChargerEmulator\Http\Router;

it('routes requests and returns explicit responses', function (): void {
    $router = new Router();

    $router->add('POST', '/probe', static function (Request $request): Response {
        return Response::json(202, ['received' => $request->form['value'] ?? null]);
    });

    $response = $router->dispatch(new Request('POST', '/probe', [], ['value' => 'ready']));

    $decoded = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);

    expect($response->status !== 202 || ($decoded['received'] ?? null) !== 'ready')->toBeFalse('The router should dispatch the matching request and return its response.');

    expect(($response->headers['Content-Type'] ?? null) !== 'application/json; charset=utf-8')->toBeFalse('JSON responses should carry the JSON content type.');

    $missing = $router->dispatch(new Request('GET', '/missing', [], []));

    expect($missing->status !== 404)->toBeFalse('The router should return 404 when no route matches.');

});
