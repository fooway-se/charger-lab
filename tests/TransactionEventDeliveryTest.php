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

    public array $calls = [];

    public bool $fail = false;

    public int $failuresRemaining = 0;

    public function send(string $message): void
    {
        $this->call = json_decode($message, true, flags: JSON_THROW_ON_ERROR);
        $this->calls[] = $this->call;
    }

    public function receive(): string
    {
        if ($this->fail || $this->failuresRemaining > 0) {
            $this->failuresRemaining = max(0, $this->failuresRemaining - 1);

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

it('retries a TransactionEvent after CALLERROR with its original identity and stops after success', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $sessions = new OutletSessionRepository($database);
    $outbox = new TransactionEventOutboxRepository($database);
    $settings = new DeviceModelSettingsRepository($database);
    $settings->set($chargerId, 'MessageAttemptsTransactionEvent', '3');
    $settings->set($chargerId, 'MessageAttemptIntervalTransactionEvent', '0');
    $queuedEvent = $outbox->enqueue($chargerId, [
        'eventType' => 'Updated',
        'seqNo' => 1,
        'transactionInfo' => ['transactionId' => 'callerror-retry-transaction'],
    ]);
    $connection = new TransactionDeliveryTestConnection();
    $connection->failuresRemaining = 1;
    $delivery = new TransactionEventDelivery(
        $outbox,
        $sessions,
        $settings,
        static fn (int $requestedChargerId): OcppClient => new OcppClient($connection),
    );

    expect(fn () => $delivery->deliverPending($chargerId))->toThrow(OcppProtocolException::class)
        ->and($delivery->deliverPending($chargerId))->toBeTrue()
        ->and($delivery->deliverPending($chargerId))->toBeFalse()
        ->and(array_column($connection->calls, 1))->toBe([$queuedEvent['message_id'], $queuedEvent['message_id']])
        ->and($outbox->hasPending($chargerId))->toBeFalse();

    $row = $database->query('SELECT attempt_count, status FROM transaction_event_outbox')->fetch();
    expect($row)->toBe(['attempt_count' => 1, 'status' => 'delivered']);
});

it('stops retrying a TransactionEvent after the configured attempts on CALLERROR', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $sessions = new OutletSessionRepository($database);
    $outbox = new TransactionEventOutboxRepository($database);
    $settings = new DeviceModelSettingsRepository($database);
    $settings->set($chargerId, 'MessageAttemptsTransactionEvent', '2');
    $settings->set($chargerId, 'MessageAttemptIntervalTransactionEvent', '0');
    $queuedEvent = $outbox->enqueue($chargerId, [
        'eventType' => 'Updated',
        'seqNo' => 1,
        'transactionInfo' => ['transactionId' => 'callerror-limit-transaction'],
    ]);
    $connection = new TransactionDeliveryTestConnection();
    $connection->fail = true;
    $delivery = new TransactionEventDelivery(
        $outbox,
        $sessions,
        $settings,
        static fn (int $requestedChargerId): OcppClient => new OcppClient($connection),
    );

    expect(fn () => $delivery->deliverPending($chargerId))->toThrow(OcppProtocolException::class)
        ->and(fn () => $delivery->deliverPending($chargerId))->toThrow(OcppProtocolException::class)
        ->and($delivery->deliverPending($chargerId))->toBeFalse()
        ->and(array_column($connection->calls, 1))->toBe([$queuedEvent['message_id'], $queuedEvent['message_id']])
        ->and($outbox->hasPending($chargerId))->toBeFalse();

    $row = $database->query('SELECT attempt_count, status FROM transaction_event_outbox')->fetch();
    expect($row)->toBe(['attempt_count' => 2, 'status' => 'discarded']);
});
