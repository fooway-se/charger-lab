<?php

declare(strict_types=1);

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

final class IncomingCallConnection implements Connection
{
    public ?string $sentMessage = null;

    public function send(string $message): void
    {
        $this->sentMessage = $message;
    }

    public function receive(): string
    {
        throw new RuntimeException('No response is expected.');
    }
}

it('propagates correlated OCPP-J CALLERROR responses', function (): void {
    $client = new OcppClient(new CallErrorConnection());

    expect(fn () => $client->request('Authorize', ['idToken' => ['idToken' => 'A1B2', 'type' => 'ISO14443']]))
        ->toThrow(OcppProtocolException::class, 'NotImplemented')
        ->toThrow(OcppProtocolException::class, 'Authorize is not supported');
});

it('returns SecurityError for CSMS calls while boot status is Rejected', function (): void {
    $connection = new IncomingCallConnection();
    $client = new OcppClient($connection);

    $client->answerIncomingCallMessage('[2,"rejected-call","GetVariables",{}]', 'Rejected');

    $response = json_decode((string) $connection->sentMessage, false, flags: JSON_THROW_ON_ERROR);
    expect($response[0])->toBe(4);
    expect($response[1])->toBe('rejected-call');
    expect($response[2])->toBe('SecurityError');
    expect($response[3])->toBe('The charging station has not been accepted by the CSMS.');
    expect($response[4])->toBeInstanceOf(stdClass::class);
});
