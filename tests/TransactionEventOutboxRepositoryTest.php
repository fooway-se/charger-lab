<?php

declare(strict_types=1);

use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\TransactionEventOutboxRepository;

it('persists transaction events and retries them with the same message identity', function (): void {
    $directory = sys_get_temp_dir() . '/charger-transaction-outbox-' . bin2hex(random_bytes(6));
    $databasePath = $directory . '/emulator.sqlite';
    $database = Database::connect($databasePath);
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outbox = new TransactionEventOutboxRepository($database);
    $payload = [
        'eventType' => 'Started',
        'timestamp' => '2026-11-04T12:00:00+00:00',
        'triggerReason' => 'CablePluggedIn',
        'seqNo' => 0,
        'transactionInfo' => ['transactionId' => 'outbox-transaction'],
    ];
    $queued = $outbox->enqueue($chargerId, $payload);
    $firstAttempt = $outbox->nextPending($chargerId, 1_800_000_000);
    $outbox->recordFailure($queued['id'], 'CSMS did not answer', 3, 2, 1_800_000_000);
    unset($outbox, $database);

    $database = Database::connect($databasePath);
    $outbox = new TransactionEventOutboxRepository($database);
    $waitingAttempt = $outbox->nextPending($chargerId, 1_800_000_001);
    $retryAttempt = $outbox->nextPending($chargerId, 1_800_000_002);
    $outbox->recordFailure($queued['id'], 'CSMS returned CALLERROR', 3, 2, 1_800_000_002);
    $secondWaitingAttempt = $outbox->nextPending($chargerId, 1_800_000_005);
    $secondRetryAttempt = $outbox->nextPending($chargerId, 1_800_000_006);

    expect($firstAttempt['message_id'] ?? null)->toBe($queued['message_id'])
        ->and($waitingAttempt)->toBeNull()
        ->and($retryAttempt['message_id'] ?? null)->toBe($queued['message_id'])
        ->and($retryAttempt['payload'] ?? null)->toBe($payload)
        ->and($secondWaitingAttempt)->toBeNull()
        ->and($secondRetryAttempt['message_id'] ?? null)->toBe($queued['message_id']);

    $outbox->recordFailure($queued['id'], 'CSMS still rejects the message', 3, 2, 1_800_000_006);
    expect($outbox->nextPending($chargerId, 1_800_001_000))->toBeNull()
        ->and($outbox->hasPending($chargerId, 'outbox-transaction'))->toBeFalse();

    unset($outbox, $database);
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    rmdir($directory);
});

it('does not deliver a later transaction event before an earlier event retry is due', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outbox = new TransactionEventOutboxRepository($database);
    $first = $outbox->enqueue($chargerId, [
        'eventType' => 'Started',
        'seqNo' => 0,
        'transactionInfo' => ['transactionId' => 'fifo-transaction'],
    ]);
    $outbox->recordFailure($first['id'], 'Temporary delivery failure', 3, 10, 1_800_000_000);
    $outbox->enqueue($chargerId, [
        'eventType' => 'Updated',
        'seqNo' => 1,
        'transactionInfo' => ['transactionId' => 'fifo-transaction'],
    ]);

    expect($outbox->nextPending($chargerId, 1_800_000_009))->toBeNull()
        ->and($outbox->nextPending($chargerId, 1_800_000_010)['id'] ?? null)->toBe($first['id']);
});
