<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use ChargerEmulator\Protocol\OcppCallErrorException;
use ChargerEmulator\Storage\LocalAuthorizationListRepository;

/**
 * Reports the version of the station's local authorization list.
 */
final class GetLocalListVersionRequestHandler
{
    public function __construct(
        private readonly int $chargerId,
        private readonly LocalAuthorizationListRepository $authorizationLists,
    ) {
    }

    public function getVersion(array $payload): array
    {
        $customData = $payload['customData'] ?? null;
        if (array_diff(array_keys($payload), ['customData']) !== []
            || (array_key_exists('customData', $payload)
                && (!is_array($customData)
                    || array_diff(array_keys($customData), ['vendorId']) !== []
                    || !is_string($customData['vendorId'] ?? null)
                    || $customData['vendorId'] === ''
                    || strlen($customData['vendorId']) > 255))) {
            throw new OcppCallErrorException('FormationViolation', 'GetLocalListVersion only accepts a valid customData object.');
        }

        return ['versionNumber' => $this->authorizationLists->version($this->chargerId)];
    }
}
