<?php

declare(strict_types=1);

use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\OutletSessionRepository;
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

it('stores a triggered event and advances its active session sequence atomically', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, [['number' => 1, 'evse_id' => 1, 'connector_id' => 1]]);
    $sessions->markOccupied($chargerId, 1, 1, 'triggered-transaction');
    $outbox = new TransactionEventOutboxRepository($database);
    $payload = [
        'eventType' => 'Updated',
        'triggerReason' => 'Trigger',
        'seqNo' => 1,
        'transactionInfo' => ['transactionId' => 'triggered-transaction'],
        'evse' => ['id' => 1, 'connectorId' => 1],
    ];

    $entry = $outbox->enqueueForActiveTransaction(
        $chargerId,
        $payload,
        $sessions,
        1,
        1,
        'triggered-transaction',
        0,
    );
    $session = $sessions->find($chargerId, 1, 1);

    expect($entry['payload'])->toBe($payload)
        ->and($session['seq_no'] ?? null)->toBe(1)
        ->and($database->inTransaction())->toBeFalse();
});

it('rolls back a triggered event if its transaction sequence cannot advance', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, [['number' => 1, 'evse_id' => 1, 'connector_id' => 1]]);
    $outbox = new TransactionEventOutboxRepository($database);
    $payload = [
        'eventType' => 'Updated',
        'triggerReason' => 'Trigger',
        'seqNo' => 1,
        'transactionInfo' => ['transactionId' => 'missing-active-transaction'],
        'evse' => ['id' => 1, 'connectorId' => 1],
    ];

    expect(fn () => $outbox->enqueueForActiveTransaction(
        $chargerId,
        $payload,
        $sessions,
        1,
        1,
        'missing-active-transaction',
        0,
    ))->toThrow(RuntimeException::class)
        ->and($outbox->nextPending($chargerId))->toBeNull()
        ->and($database->inTransaction())->toBeFalse();
});

it('marks queued transaction events offline when the charger loses its connection', function (): void {
    $directory = sys_get_temp_dir() . '/charger-offline-outbox-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $databasePath = $directory . '/emulator.sqlite';
    $database = Database::connect($databasePath);
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outbox = new TransactionEventOutboxRepository($database);
    $pending = $outbox->enqueue($chargerId, [
        'eventType' => 'Started',
        'seqNo' => 0,
        'transactionInfo' => ['transactionId' => 'offline-transaction'],
    ]);
    $delivered = $outbox->enqueue($chargerId, [
        'eventType' => 'Updated',
        'seqNo' => 1,
        'transactionInfo' => ['transactionId' => 'offline-transaction'],
    ]);
    $outbox->markDelivered($delivered['id']);

    $outbox->markPendingOffline($chargerId);
    unset($outbox, $database);

    $database = Database::connect($databasePath);
    $outbox = new TransactionEventOutboxRepository($database);
    $pendingAfterReopen = $outbox->nextPending($chargerId);
    $deliveredRow = $database->query(
        "SELECT payload, status FROM transaction_event_outbox WHERE id = {$delivered['id']}",
    )->fetch(PDO::FETCH_ASSOC);

    expect($pendingAfterReopen['id'] ?? null)->toBe($pending['id'])
        ->and($pendingAfterReopen['message_id'] ?? null)->toBe($pending['message_id'])
        ->and($pendingAfterReopen['payload']['offline'] ?? null)->toBeTrue()
        ->and($deliveredRow['status'] ?? null)->toBe('delivered')
        ->and(json_decode($deliveredRow['payload'] ?? '{}', true)['offline'] ?? null)->toBeNull();

    unset($outbox, $database);
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    rmdir($directory);
});

it('rolls back all offline flags if a queued payload cannot be decoded', function (): void {
    $database = Database::connect(':memory:');
    $chargerId = (new ChargerRepository($database))->createDefault();
    $outbox = new TransactionEventOutboxRepository($database);
    $queued = $outbox->enqueue($chargerId, [
        'eventType' => 'Started',
        'seqNo' => 0,
        'transactionInfo' => ['transactionId' => 'offline-rollback-transaction'],
    ]);
    $insertInvalidPayload = $database->prepare(<<<'SQL'
        INSERT INTO transaction_event_outbox
            (charger_id, transaction_id, seq_no, message_id, payload, status)
        VALUES (:charger_id, 'invalid-payload-transaction', 0, :message_id, '{', 'pending')
        SQL);
    $insertInvalidPayload->execute([
        'charger_id' => $chargerId,
        'message_id' => bin2hex(random_bytes(16)),
    ]);

    expect(fn () => $outbox->markPendingOffline($chargerId))->toThrow(JsonException::class)
        ->and($database->inTransaction())->toBeFalse();

    $queuedRow = $database->query(
        "SELECT payload FROM transaction_event_outbox WHERE id = {$queued['id']}",
    )->fetch(PDO::FETCH_ASSOC);
    expect(json_decode($queuedRow['payload'] ?? '{}', true)['offline'] ?? null)->toBeNull();
});
