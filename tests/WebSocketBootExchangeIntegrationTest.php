<?php

declare(strict_types=1);

use ChargerEmulator\Protocol\OcppClient;
use ChargerEmulator\Protocol\OcppProtocolException;
use ChargerEmulator\Protocol\WebSocketConnection;

it('exchanges OCPP frames over a WebSocket connection', function (): void {
    $reservation = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);

    expect(!is_resource($reservation))->toBeFalse('Could not reserve an integration-test port.');

    $address = stream_socket_get_name($reservation, false);

    fclose($reservation);

    expect(!is_string($address) || !str_contains($address, ':'))->toBeFalse('Could not determine the integration-test port.');

    $port = (int) substr(strrchr($address, ':'), 1);

    $environment = [
        'PATH' => (string) getenv('PATH'),
        'FAKE_CSMS_PORT' => (string) $port,
        'FAKE_CSMS_FRAGMENT_RESPONSE' => '1',
        'FAKE_CSMS_BASIC_AUTH_STATION' => 'IntegrationCharger',
        'FAKE_CSMS_BASIC_AUTH_PASSWORD' => 'integration-secret',
    ];

    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/../bin/fake-csms.php'],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
        $environment,
    );

    expect(!is_resource($process))->toBeFalse('Could not start the fake CSMS integration fixture.');

    try {
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $ready = false;
        $deadline = microtime(true) + 3;
        while (microtime(true) < $deadline) {
            set_error_handler(static fn (): bool => true);
            try {
                $probe = stream_socket_client("tcp://127.0.0.1:{$port}", $probeErrorNumber, $probeErrorMessage, 0.1);
            } finally {
                restore_error_handler();
            }

            if (is_resource($probe)) {
                fclose($probe);
                $ready = true;
                break;
            }

            usleep(20_000);
        }
        expect(!$ready)->toBeFalse('The fake CSMS did not open its listening port.');

        $exchanges = [];
        $client = new OcppClient(
            new WebSocketConnection(
                "ws://127.0.0.1:{$port}/ocpp/IntegrationCharger",
                username: 'IntegrationCharger',
                password: 'integration-secret',
            ),
            static function (string $direction, string $action, string $message) use (&$exchanges): void {
                $exchanges[] = [$direction, $action, json_decode($message, true, flags: JSON_THROW_ON_ERROR)];
            },
        );
        $result = $client->request('BootNotification', [
            'chargingStation' => ['model' => 'Integration', 'vendorName' => 'Test'],
            'reason' => 'PowerUp',
        ]);

        expect(($result['status'] ?? null) !== 'Accepted')->toBeFalse('The fake CSMS did not accept BootNotification.');
        expect(count($exchanges) !== 2 || $exchanges[0][0] !== 'outgoing' || $exchanges[1][0] !== 'incoming')->toBeFalse('Expected outgoing and incoming OCPP messages to be observable for logging.');
        expect(($exchanges[0][1] ?? null) !== 'BootNotification'
            || ($exchanges[0][2][2] ?? null) !== 'BootNotification'
            || ($exchanges[1][2][0] ?? null) !== 3)->toBeFalse('Expected a BootNotification CALL and CALLRESULT in the exchange log.');
        expect(($exchanges[0][2][1] ?? null) !== ($exchanges[1][2][1] ?? null))->toBeFalse('Expected the CALLRESULT to correlate with the BootNotification CALL.');

        unset($client);

        $unsupportedClient = new OcppClient(new WebSocketConnection(
            "ws://127.0.0.1:{$port}/ocpp/IntegrationCharger",
            username: 'IntegrationCharger',
            password: 'integration-secret',
        ));
        expect(fn () => $unsupportedClient->request('GetVariables', []))
            ->toThrow(OcppProtocolException::class, 'NotImplemented');

    } finally {
        proc_terminate($process);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    }
})->group('integration');
