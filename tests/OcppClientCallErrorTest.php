<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use ChargerEmulator\Protocol\Connection;
use ChargerEmulator\Protocol\OcppClient;
use ChargerEmulator\Protocol\OcppProtocolException;

final class CallErrorConnection implements Connection
{
    private string $uniqueId = '';

    public function send(string $message): void
    {
        $call = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
        $this->uniqueId = $call[1];
    }

    public function receive(): string
    {
        return json_encode([
            4,
            $this->uniqueId,
            'NotImplemented',
            'Authorize is not supported by this CSMS.',
            new stdClass(),
        ], JSON_THROW_ON_ERROR);
    }
}

$client = new OcppClient(new CallErrorConnection());

try {
    $client->request('Authorize', ['idToken' => ['idToken' => 'A1B2', 'type' => 'ISO14443']]);
    throw new RuntimeException('Expected the OCPP-J CALLERROR to be reported.');
} catch (OcppProtocolException $exception) {
    if (!str_contains($exception->getMessage(), 'NotImplemented')
        || !str_contains($exception->getMessage(), 'Authorize is not supported')) {
        throw new RuntimeException('Expected CALLERROR details to be available to the caller.', previous: $exception);
    }
}

echo "OCPP-J CALLERROR: PASS\n";
