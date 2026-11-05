<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use ChargerEmulator\Storage\OcppCommandRepository;
use ChargerEmulator\Storage\OutletSessionRepository;

/**
 * Validates CSMS remote-stop requests and queues the active transaction's outlet.
 */
final class RemoteStopRequestHandler
{
    public function __construct(
        private readonly int $chargerId,
        private readonly OutletSessionRepository $outletSessions,
        private readonly OcppCommandRepository $commands,
    ) {
    }

    public function requestStop(array $payload): array
    {
        $transactionId = $payload['transactionId'] ?? null;
        if (!is_string($transactionId) || $transactionId === '' || strlen($transactionId) > 36) {
            return ['status' => 'Rejected'];
        }

        $session = $this->outletSessions->findByTransactionId($this->chargerId, $transactionId);
        if ($session === null) {
            return ['status' => 'Rejected'];
        }

        $this->commands->enqueue($this->chargerId, 'RemoteStop', [
            'transactionId' => $transactionId,
            'evseId' => $session['evse_id'],
            'connectorId' => $session['connector_id'],
            'seqNo' => $session['seq_no'],
        ]);

        return ['status' => 'Accepted'];
    }
}
