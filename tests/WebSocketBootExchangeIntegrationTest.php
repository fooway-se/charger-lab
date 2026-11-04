<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use ChargerEmulator\Protocol\OcppClient;
use ChargerEmulator\Protocol\OcppProtocolException;
use ChargerEmulator\Protocol\WebSocketConnection;

$reservation = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($reservation === false || !socket_bind($reservation, '127.0.0.1', 0) || !socket_getsockname($reservation, $address, $port)) {
    throw new RuntimeException('Could not reserve an integration-test port.');
}
socket_close($reservation);

$environment = [
    'PATH' => (string) getenv('PATH'),
    'FAKE_CSMS_PORT' => (string) $port,
    'FAKE_CSMS_FRAGMENT_RESPONSE' => '1',
];
$process = proc_open(
    [PHP_BINARY, __DIR__ . '/../bin/fake-csms.php'],
    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
    $pipes,
    dirname(__DIR__),
    $environment,
);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start the fake CSMS integration fixture.');
}

try {
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $ready = false;
    $deadline = microtime(true) + 3;
    while (microtime(true) < $deadline) {
        $probe = @stream_socket_client("tcp://127.0.0.1:{$port}", $probeErrorNumber, $probeErrorMessage, 0.1);
        if (is_resource($probe)) {
            fclose($probe);
            $ready = true;
            break;
        }

        usleep(20_000);
    }
    if (!$ready) {
        throw new RuntimeException('The fake CSMS did not open its listening port.');
    }

    $exchanges = [];
    $client = new OcppClient(
        new WebSocketConnection("ws://127.0.0.1:{$port}/ocpp/IntegrationCharger"),
        static function (string $direction, string $message) use (&$exchanges): void {
            $exchanges[] = [$direction, json_decode($message, true, flags: JSON_THROW_ON_ERROR)];
        },
    );
    $result = $client->request('BootNotification', [
        'chargingStation' => ['model' => 'Integration', 'vendorName' => 'Test'],
        'reason' => 'PowerUp',
    ]);

    if (($result['status'] ?? null) !== 'Accepted') {
        throw new RuntimeException('The fake CSMS did not accept BootNotification.');
    }
    if (count($exchanges) !== 2 || $exchanges[0][0] !== 'outgoing' || $exchanges[1][0] !== 'incoming') {
        throw new RuntimeException('Expected outgoing and incoming OCPP messages to be observable for logging.');
    }
    if (($exchanges[0][1][2] ?? null) !== 'BootNotification' || ($exchanges[1][1][0] ?? null) !== 3) {
        throw new RuntimeException('Expected a BootNotification CALL and CALLRESULT in the exchange log.');
    }
    if (($exchanges[0][1][1] ?? null) !== ($exchanges[1][1][1] ?? null)) {
        throw new RuntimeException('Expected the CALLRESULT to correlate with the BootNotification CALL.');
    }

    unset($client);

    try {
        $unsupportedClient = new OcppClient(new WebSocketConnection("ws://127.0.0.1:{$port}/ocpp/IntegrationCharger"));
        $unsupportedClient->request('GetVariables', []);
        throw new RuntimeException('Expected the fake CSMS to reject an unsupported action.');
    } catch (OcppProtocolException $exception) {
        if (!str_contains($exception->getMessage(), 'NotImplemented')) {
            throw new RuntimeException('Expected an OCPP-J NotImplemented CALLERROR.', previous: $exception);
        }
    }

    echo "WebSocket BootNotification integration: PASS\n";
} finally {
    proc_terminate($process);
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}
