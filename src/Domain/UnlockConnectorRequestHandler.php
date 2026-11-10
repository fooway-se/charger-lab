<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use ChargerEmulator\Protocol\OcppCallErrorException;
use ChargerEmulator\Storage\OutletSessionRepository;

/**
 * Handles remote connector unlock requests for one charger.
 */
final class UnlockConnectorRequestHandler
{
    public function __construct(
        private readonly int $chargerId,
        private readonly OutletSessionRepository $outletSessions,
    ) {
    }

    public function unlock(array $payload): array
    {
        $evseId = $payload['evseId'] ?? null;
        $connectorId = $payload['connectorId'] ?? null;
        if (!is_int($evseId) || !is_int($connectorId)) {
            throw new OcppCallErrorException('FormationViolation', 'UnlockConnector requires integer evseId and connectorId values.');
        }

        $session = $this->outletSessions->find($this->chargerId, $evseId, $connectorId);
        if ($session === null) {
            return ['status' => 'UnknownConnector'];
        }
        if ($session['connector_status'] === 'Occupied' && $session['transaction_id'] !== null) {
            return ['status' => 'OngoingAuthorizedTransaction'];
        }

        return ['status' => 'Unlocked'];
    }
}
