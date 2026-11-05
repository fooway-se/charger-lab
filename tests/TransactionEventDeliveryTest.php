<?php

declare(strict_types=1);

use ChargerEmulator\Protocol\Connection;
use ChargerEmulator\Protocol\OcppClient;
use ChargerEmulator\Protocol\OcppProtocolException;
use ChargerEmulator\Protocol\TransactionEventDelivery;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\DeviceModelSettingsRepository;
use ChargerEmulator\Storage\OutletSessionRepository;
use ChargerEmulator\Storage\TransactionEventOutboxRepository;

final class TransactionDeliveryTestConnection implements Connection
{
    public ?array $call = null;

    public bool $fail = false;

    public function send(string $message): void
    {
        $this->call = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
    }

    public function receive(): string
    {
        if ($this->fail) {
            return json_encode([4, $this->call[1], 'InternalError', 'Temporary CSMS error.', new stdClass()], JSON_THROW_ON_ERROR);
        }

        return json_encode([3, $this->call[1], new stdClass()], JSON_THROW_ON_ERROR);
    }
}

it('delivers a queued transaction event with its persisted message ID', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outletSessions = new OutletSessionRepository($database);
    $outbox = new TransactionEventOutboxRepository($database);
    $queuedEvent = $outbox->enqueue($chargerId, [
        'eventType' => 'Started',
        'seqNo' => 0,
        'transactionInfo' => ['transactionId' => 'delivery-transaction'],
    ]);
    $connection = new TransactionDeliveryTestConnection();
    $delivery = new TransactionEventDelivery(
        $outbox,
        $outletSessions,
        new DeviceModelSettingsRepository($database),
        static fn (int $requestedChargerId): OcppClient => new OcppClient($connection),
    );

    $response = $delivery->deliverThrough($chargerId, $queuedEvent['id']);

    expect($connection->call[1] ?? null)->toBe($queuedEvent['message_id'])
        ->and($connection->call[2] ?? null)->toBe('TransactionEvent')
        ->and($response)->toBe([])
        ->and($outbox->hasPending($chargerId))->toBeFalse();
});

it('records a failed delivery for retry without losing its queued event', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outletSessions = new OutletSessionRepository($database);
    $outbox = new TransactionEventOutboxRepository($database);
    $queuedEvent = $outbox->enqueue($chargerId, [
        'eventType' => 'Started',
        'seqNo' => 0,
        'transactionInfo' => ['transactionId' => 'retry-transaction'],
    ]);
    $connection = new TransactionDeliveryTestConnection();
    $connection->fail = true;
    $delivery = new TransactionEventDelivery(
        $outbox,
        $outletSessions,
        new DeviceModelSettingsRepository($database),
        static fn (int $requestedChargerId): OcppClient => new OcppClient($connection),
    );

    expect(fn () => $delivery->deliverThrough($chargerId, $queuedEvent['id']))
        ->toThrow(OcppProtocolException::class)
        ->and($outbox->hasPending($chargerId, 'retry-transaction'))->toBeTrue();

    $row = $database->query('SELECT attempt_count, status FROM transaction_event_outbox')->fetch();
    expect($row)->toBe(['attempt_count' => 1, 'status' => 'pending']);
});
