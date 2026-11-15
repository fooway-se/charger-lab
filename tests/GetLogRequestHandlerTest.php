<?php

declare(strict_types=1);

use ChargerEmulator\Domain\GetLogRequestHandler;
use ChargerEmulator\Protocol\OcppCallErrorException;

it('accepts diagnostics and security log requests with distinct filenames', function (string $logType, int $requestId, string $filename): void {
    $response = (new GetLogRequestHandler())->handle([
        'logType' => $logType,
        'requestId' => $requestId,
        'log' => ['remoteLocation' => 'http://127.0.0.1:8080/logs/'],
    ]);

    expect($response)->toBe(['status' => 'Accepted', 'filename' => $filename]);
})->with([
    'diagnostics' => ['DiagnosticsLog', 42, 'diagnostics-42.log'],
    'security' => ['SecurityLog', 13, 'security-13.log'],
]);

it('rejects log requests whose fields do not match the OCPP payload', function (array $payload): void {
    expect(fn () => (new GetLogRequestHandler())->handle($payload))
        ->toThrow(OcppCallErrorException::class, 'GetLog requires valid log type, request ID, location and retry settings.');
})->with([
    'missing log type' => [['requestId' => 1, 'log' => ['remoteLocation' => 'http://127.0.0.1/log']]],
    'negative request ID' => [['logType' => 'DiagnosticsLog', 'requestId' => -1, 'log' => ['remoteLocation' => 'http://127.0.0.1/log']]],
    'unknown log type' => [['logType' => 'OtherLog', 'requestId' => 1, 'log' => ['remoteLocation' => 'http://127.0.0.1/log']]],
    'invalid remote location' => [['logType' => 'DiagnosticsLog', 'requestId' => 1, 'log' => ['remoteLocation' => 'not a URL']]],
    'invalid retry interval' => [['logType' => 'DiagnosticsLog', 'requestId' => 1, 'retryInterval' => -1, 'log' => ['remoteLocation' => 'http://127.0.0.1/log']]],
    'invalid nested custom data' => [['customData' => null, 'logType' => 'DiagnosticsLog', 'requestId' => 1, 'log' => ['remoteLocation' => 'http://127.0.0.1/log']]],
]);
