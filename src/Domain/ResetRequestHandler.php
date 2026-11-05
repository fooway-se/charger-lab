<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use ChargerEmulator\Storage\ResetRepository;

/**
 * Handles CSMS Reset requests for station-wide reset behavior.
 */
final class ResetRequestHandler
{
    public function __construct(
        private readonly int $chargerId,
        private readonly ResetRepository $resets,
    ) {
    }

    public function requestReset(array $payload): array
    {
        return ['status' => $this->resets->request($this->chargerId, $payload)];
    }
}
