<?php

declare(strict_types=1);

use ChargerEmulator\Protocol\Connection;
use ChargerEmulator\Protocol\OcppClient;

final class FakeCsmsConnection implements Connection
{
    public ?array $receivedCall = null;

    public function send(string $message): void
    {
        $this->receivedCall = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
    }

    public function receive(): string
    {
        expect($this->receivedCall === null)->toBeFalse('The emulator did not send a request.');

        return json_encode([
            3,
            $this->receivedCall[1],
            ['status' => 'Accepted', 'currentTime' => '2026-11-04T12:00:00Z', 'interval' => 300],
        ], JSON_THROW_ON_ERROR);
    }
}

it('sends and validates a BootNotification exchange', function (): void {
    $connection = new FakeCsmsConnection();

    $client = new OcppClient($connection);

    $payload = [
        'chargingStation' => ['model' => 'Demo', 'vendorName' => 'Local'],
        'reason' => 'PowerUp',
    ];

    $result = $client->request('BootNotification', $payload);

    expect($connection->receivedCall === null
        || $connection->receivedCall[0] !== 2
        || $connection->receivedCall[2] !== 'BootNotification'
        || $connection->receivedCall[3] !== $payload)->toBeFalse('Expected a valid OCPP-J BootNotification CALL with the configured payload.');

    expect($result !== ['status' => 'Accepted', 'currentTime' => '2026-11-04T12:00:00Z', 'interval' => 300])->toBeFalse('Expected the matching Accepted CALLRESULT payload.');

});
