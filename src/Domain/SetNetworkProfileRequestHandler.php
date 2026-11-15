<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use ChargerEmulator\Storage\NetworkProfileRepository;

/**
 * Stores OCPP 2.0.1 network profiles supported by this emulator.
 */
final class SetNetworkProfileRequestHandler
{
    public function __construct(
        private readonly NetworkProfileRepository $profiles,
        private readonly int $chargerId = 1,
        private readonly ?int $activeSecurityProfile = null,
    ) {
    }

    public function setNetworkProfile(array $payload): array
    {
        $slot = $payload['configurationSlot'] ?? null;
        $profile = $payload['connectionData'] ?? null;
        if (!is_int($slot) || $slot < 0 || $slot > 2 || !is_array($profile) || !$this->supports($profile)) {
            return ['status' => 'Rejected'];
        }
        if ($this->activeSecurityProfile !== null
            && $profile['securityProfile'] < $this->activeSecurityProfile) {
            return ['status' => 'Rejected'];
        }

        $this->profiles->set($this->chargerId, $profile, $slot);

        return ['status' => 'Accepted'];
    }

    private function supports(array $profile): bool
    {
        $url = $profile['ocppCsmsUrl'] ?? null;
        if (!is_string($url) || array_diff(array_keys($profile), [
            'apn', 'customData', 'messageTimeout', 'ocppCsmsUrl', 'ocppInterface', 'ocppTransport', 'ocppVersion', 'securityProfile', 'vpn',
        ]) !== []) {
            return false;
        }

        try {
            $parts = parse_url($url);
        } catch (\ValueError) {
            return false;
        }
        $scheme = $parts['scheme'] ?? null;

        return ($profile['ocppVersion'] ?? null) === 'OCPP20'
            && ($profile['ocppTransport'] ?? null) === 'JSON'
            && is_int($profile['messageTimeout'] ?? null)
            && $profile['messageTimeout'] > 0
            && ($profile['securityProfile'] ?? null) === ($scheme === 'wss' ? 2 : 1)
            && ($profile['ocppInterface'] ?? null) === 'Wired0'
            && !isset($profile['apn'])
            && !isset($profile['vpn'])
            && strlen($url) <= 2000
            && is_array($parts)
            && in_array($scheme, ['ws', 'wss'], true)
            && isset($parts['host'])
            && !isset($parts['user'], $parts['pass'], $parts['fragment'])
            && preg_match('/[\x00-\x20\x7f]/', $url) !== 1;
    }
}
