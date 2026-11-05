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
    public function __construct(
        private readonly int $chargerId,
        private readonly OutletSessionRepository $outletSessions,
        private readonly TransactionEventOutboxRepository $transactionEvents,
    ) {
    }

    public function getTransactionStatus(array $payload): array
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
                return $this->requestedTransactionStatus($transactionId, true);
            }
        }

        return $this->requestedTransactionStatus($transactionId, false);
    }

    private function requestedTransactionStatus(string $transactionId, bool $ongoing): array
    {
        return [
            'ongoingIndicator' => $ongoing,
            'messagesInQueue' => $this->transactionEvents->hasPending($this->chargerId, $transactionId),
        ];
    }
}
