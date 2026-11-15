<?php

declare(strict_types=1);

use ChargerEmulator\Domain\TransactionStatusRequestHandler;
use ChargerEmulator\Storage\ChargerRepository;
use ChargerEmulator\Storage\Database;
use ChargerEmulator\Storage\OutletSessionRepository;
use ChargerEmulator\Storage\TransactionEventOutboxRepository;

it('reports whether a requested transaction or any transaction is ongoing', function (): void {
    $directory = sys_get_temp_dir() . '/charger-transaction-status-' . bin2hex(random_bytes(6));
    mkdir($directory);
    $databasePath = $directory . '/emulator.sqlite';
    $database = Database::connect($databasePath);
    $chargerId = (new ChargerRepository($database))->createDefault();
    $sessions = new OutletSessionRepository($database);
    $sessions->initialize($chargerId, [
        ['number' => 1, 'evse_id' => 1, 'connector_id' => 1],
        ['number' => 2, 'evse_id' => 2, 'connector_id' => 1],
    ]);
    $sessions->markOccupied($chargerId, 1, 1, 'active-transaction');
    $events = new TransactionEventOutboxRepository($database);
    $handler = new TransactionStatusRequestHandler($chargerId, $sessions, $events);

    expect($handler->getTransactionStatus(['transactionId' => 'active-transaction']))
        ->toBe(['ongoingIndicator' => true, 'messagesInQueue' => false])
        ->and($handler->getTransactionStatus(['transactionId' => 'unknown-transaction']))
        ->toBe(['ongoingIndicator' => false, 'messagesInQueue' => false])
        ->and($handler->getTransactionStatus([]))
        ->toBe(['messagesInQueue' => false]);

    $events->enqueue($chargerId, [
        'eventType' => 'Started',
        'seqNo' => 0,
        'transactionInfo' => ['transactionId' => 'active-transaction'],
    ]);
    expect($handler->getTransactionStatus(['transactionId' => 'active-transaction']))
        ->toBe(['ongoingIndicator' => true, 'messagesInQueue' => true])
        ->and($handler->getTransactionStatus([]))
        ->toBe(['messagesInQueue' => true]);

    $endedEvent = $events->enqueue($chargerId, [
        'eventType' => 'Ended',
        'seqNo' => 2,
        'transactionInfo' => ['transactionId' => 'ended-transaction'],
    ]);
    expect($handler->getTransactionStatus(['transactionId' => 'ended-transaction']))
        ->toBe(['ongoingIndicator' => false, 'messagesInQueue' => true]);
    $events->markDelivered($endedEvent['id']);
    expect($handler->getTransactionStatus(['transactionId' => 'ended-transaction']))
        ->toBe(['ongoingIndicator' => false, 'messagesInQueue' => false]);

    unset($handler, $sessions, $database);
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }

    rmdir($directory);
});
