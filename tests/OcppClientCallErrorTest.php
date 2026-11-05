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

final class DeferredIncomingCallConnection implements Connection
{
    /**
     * @var list<array<mixed>>
     */
    public array $sentMessages = [];

    /**
     * @var list<string>
     */
    private array $incomingMessages;

    public function __construct(string $responseId = 'boot-call')
    {
        $this->incomingMessages = [
            '[2,"availability-call","ChangeAvailability",{}]',
            json_encode([3, $responseId, new stdClass()], JSON_THROW_ON_ERROR),
        ];
    }

    public function send(string $message): void
    {
        $frame = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
        $this->sentMessages[] = $frame;
        if (($frame[0] ?? null) === 2 && ($frame[2] ?? null) === 'StatusNotification') {
            $this->incomingMessages[] = json_encode([3, $frame[1], new stdClass()], JSON_THROW_ON_ERROR);
        }
    }

    public function receive(): string
    {
        return array_shift($this->incomingMessages) ?? throw new RuntimeException('No fake CSMS response is queued.');
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

it('runs availability follow-up messages after returning the CSMS call response', function (): void {
    $connection = new IncomingCallConnection();
    $responseWasSent = false;
    $client = new OcppClient(
        $connection,
        incomingCallHandler: static fn (string $action, array $payload): array => ['status' => 'Accepted'],
        incomingCallAfterResponse: static function () use ($connection, &$responseWasSent): void {
            $response = json_decode((string) $connection->sentMessage, true, flags: JSON_THROW_ON_ERROR);
            $responseWasSent = ($response[0] ?? null) === 3 && ($response[1] ?? null) === 'availability-call';
        },
    );

    $client->answerIncomingCallMessage('[2,"availability-call","ChangeAvailability",{}]', 'Accepted');

    expect($responseWasSent)->toBeTrue();
});

it('defers availability status notifications until the current OCPP response is correlated', function (): void {
    $connection = new DeferredIncomingCallConnection();
    $client = null;
    $client = new OcppClient(
        $connection,
        incomingCallHandler: static fn (string $action, array $payload): array => ['status' => 'Accepted'],
        incomingCallAfterResponse: static function () use (&$client): void {
            $client->request('StatusNotification', [
                'connectorStatus' => 'Unavailable',
                'evseId' => 1,
                'connectorId' => 1,
            ]);
        },
    );

    $response = $client->request('BootNotification', ['reason' => 'PowerUp'], 'boot-call');

    expect($response)->toBe([])
        ->and(array_column($connection->sentMessages, 0))->toBe([2, 3, 2])
        ->and($connection->sentMessages[2][2] ?? null)->toBe('StatusNotification');
});

it('does not run deferred availability effects after a miscorrelated OCPP response', function (): void {
    $connection = new DeferredIncomingCallConnection('wrong-call');
    $followUpCalled = false;
    $client = new OcppClient(
        $connection,
        incomingCallHandler: static fn (string $action, array $payload): array => ['status' => 'Accepted'],
        incomingCallAfterResponse: static function () use (&$followUpCalled): void {
            $followUpCalled = true;
        },
    );

    expect(fn () => $client->request('BootNotification', ['reason' => 'PowerUp'], 'boot-call'))
        ->toThrow(ChargerEmulator\Protocol\OcppProtocolException::class, 'uniqueId does not match')
        ->and($followUpCalled)->toBeFalse();
});
