<?php

declare(strict_types=1);

use ChargerEmulator\Protocol\Connection;
use ChargerEmulator\Protocol\OcppClient;

final class RecordingCsmsConnection implements Connection
{
    public array $receivedOcppCall;

    public function send(string $ocppFrame): void
    {
        $this->receivedOcppCall = json_decode($ocppFrame, true, flags: JSON_THROW_ON_ERROR);
    }

    public function receive(): string
    {
        return json_encode([
            3,
            $this->receivedOcppCall[1],
            ['status' => 'Accepted', 'currentTime' => '2026-11-04T12:00:00Z', 'interval' => 300],
        ], JSON_THROW_ON_ERROR);
    }
}

it('sends and validates a BootNotification exchange', function (): void {
    $csmsConnection = new RecordingCsmsConnection();

    $ocppClient = new OcppClient($csmsConnection);

    $bootNotificationPayload = [
        'chargingStation' => ['model' => 'Demo', 'vendorName' => 'Local'],
        'reason' => 'PowerUp',
    ];

    $bootNotificationResponse = $ocppClient->request('BootNotification', $bootNotificationPayload);
    $bootNotificationCall = $csmsConnection->receivedOcppCall;

    expect($bootNotificationCall)->toHaveCount(4)
        ->and($bootNotificationCall[0])->toBe(2)
        ->and($bootNotificationCall[1])->toBeString()
        ->and($bootNotificationCall[2])->toBe('BootNotification')
        ->and($bootNotificationCall[3])->toBe($bootNotificationPayload)
        ->and($bootNotificationResponse)->toBe([
            'status' => 'Accepted',
            'currentTime' => '2026-11-04T12:00:00Z',
            'interval' => 300,
        ]);

});

it('uses a persisted message ID when retrying a TransactionEvent', function (): void {
    $csmsConnection = new RecordingCsmsConnection();
    $ocppClient = new OcppClient($csmsConnection);
    $persistedMessageId = 'persisted-transaction-event-id';
    $transactionEventPayload = [
        'eventType' => 'Updated',
        'seqNo' => 4,
        'transactionInfo' => ['transactionId' => 'transaction-4'],
    ];

    $ocppClient->request('TransactionEvent', $transactionEventPayload, $persistedMessageId);

    expect($csmsConnection->receivedOcppCall[1])->toBe($persistedMessageId);
});
