<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use ChargerEmulator\Storage\OutletSessionRepository;

/**
 * Answers CSMS transaction status requests from persisted outlet state.
 */
final class TransactionStatusRequestHandler
{
    /**
     * @param int $chargerId The station that received the CSMS request.
     * @param OutletSessionRepository $outletSessions The station's persisted outlet sessions.
     */
    public function __construct(
        private readonly int $chargerId,
        private readonly OutletSessionRepository $outletSessions,
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
            return ['messagesInQueue' => false];
        }

        if (!is_string($transactionId) || $transactionId === '' || strlen($transactionId) > 36) {
            return ['ongoingIndicator' => false, 'messagesInQueue' => false];
        }

        foreach ($this->outletSessions->allForCharger($this->chargerId) as $session) {
            if ($session['transaction_id'] === null || $session['connector_status'] !== 'Occupied') {
                continue;
            }

            if ($session['transaction_id'] === $transactionId) {
                return ['ongoingIndicator' => true, 'messagesInQueue' => false];
            }
        }

        return ['ongoingIndicator' => false, 'messagesInQueue' => false];
    }
}
