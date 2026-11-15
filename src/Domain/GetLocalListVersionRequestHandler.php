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
        if (array_diff(array_keys($payload), ['customData']) !== []
            || !CustomDataValidator::isValid($payload['customData'] ?? null, array_key_exists('customData', $payload))) {
            throw new OcppCallErrorException('FormationViolation', 'GetLocalListVersion only accepts a valid customData object.');
        }

        return ['versionNumber' => $this->authorizationLists->version($this->chargerId)];
    }
}
