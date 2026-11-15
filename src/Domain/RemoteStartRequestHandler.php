<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use ChargerEmulator\Storage\OcppCommandRepository;
use ChargerEmulator\Storage\AvailabilityRepository;
use ChargerEmulator\Storage\OutletSessionRepository;
use ChargerEmulator\Storage\ResetRepository;

/**
 * Validates CSMS remote-start requests and queues an available outlet action.
 */
final class RemoteStartRequestHandler
{
    public function __construct(
        private readonly int $chargerId,
        private readonly OutletSessionRepository $outletSessions,
        private readonly OcppCommandRepository $commands,
        private readonly AvailabilityRepository $availability,
        private readonly ResetRepository $resets,
    ) {
    }

    public function requestStart(array $payload): array
    {
        if ($this->resets->blocksNewTransactions($this->chargerId)) {
            return ['status' => 'Rejected'];
        }

        $remoteStartId = $payload['remoteStartId'] ?? null;
        $idToken = $payload['idToken'] ?? null;
        $evseId = $payload['evseId'] ?? null;
        if (!is_int($remoteStartId) || $remoteStartId < 0 || !$this->isIdToken($idToken)
            || ($evseId !== null && (!is_int($evseId) || $evseId < 0))) {
            return ['status' => 'Rejected'];
        }

        foreach ($this->outletSessions->allForCharger($this->chargerId) as $session) {
            if ($session['connector_status'] !== 'Available' || $session['transaction_id'] !== null
                || !$this->availability->canStartTransaction(
                    $this->chargerId,
                    $session['evse_id'],
                    $session['connector_id'],
                )
                || ($evseId !== null && $session['evse_id'] !== $evseId)) {
                continue;
            }

            $this->commands->enqueue($this->chargerId, 'RemoteStart', [
                'remoteStartId' => $remoteStartId,
                'idToken' => $idToken,
                'evseId' => $session['evse_id'],
                'connectorId' => $session['connector_id'],
            ]);

            return ['status' => 'Accepted'];
        }

        return ['status' => 'Rejected'];
    }

    private function isIdToken(mixed $idToken): bool
    {
        if (!is_array($idToken) || !is_string($idToken['idToken'] ?? null) || $idToken['idToken'] === ''
            || strlen($idToken['idToken']) > 36 || !is_string($idToken['type'] ?? null)) {
            return false;
        }

        return in_array($idToken['type'], [
            'Central',
            'eMAID',
            'ISO14443',
            'ISO15693',
            'KeyCode',
            'Local',
            'MacAddress',
            'NoAuthorization',
        ], true);
    }
}
