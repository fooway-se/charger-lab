<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use ChargerEmulator\Storage\OutletSessionRepository;
use ChargerEmulator\Storage\TransactionEventOutboxRepository;

/**
 * Answers CSMS transaction status requests from persisted outlet state.
 */
final class TransactionStatusRequestHandler
{
    /**
     * @param int $chargerId The station that received the CSMS request.
     * @param OutletSessionRepository $outletSessions The station's persisted outlet sessions.
     * @param TransactionEventOutboxRepository $transactionEvents The pending transaction event queue.
     */
    public function __construct(
        private readonly int $chargerId,
        private readonly OutletSessionRepository $outletSessions,
        private readonly TransactionEventOutboxRepository $transactionEvents,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{ongoingIndicator?:bool,messagesInQueue:bool}
     */
    public function handle(array $payload): array
    {
        $transactionId = $payload['transactionId'] ?? null;
        $hasRequestedTransaction = array_key_exists('transactionId', $payload);
        if (!$hasRequestedTransaction) {
            return ['messagesInQueue' => $this->transactionEvents->hasPending($this->chargerId)];
        }

        if (!is_string($transactionId) || $transactionId === '' || strlen($transactionId) > 36) {
            return ['ongoingIndicator' => false, 'messagesInQueue' => false];
        }

        foreach ($this->outletSessions->allForCharger($this->chargerId) as $session) {
            if ($session['transaction_id'] === null || $session['connector_status'] !== 'Occupied') {
                continue;
            }

            if ($session['transaction_id'] === $transactionId) {
                return [
                    'ongoingIndicator' => true,
                    'messagesInQueue' => $this->transactionEvents->hasPending($this->chargerId, $transactionId),
                ];
            }
        }

        return [
            'ongoingIndicator' => false,
            'messagesInQueue' => $this->transactionEvents->hasPending($this->chargerId, $transactionId),
        ];
    }
}
