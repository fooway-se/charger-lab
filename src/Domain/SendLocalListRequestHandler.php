<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use ChargerEmulator\Protocol\OcppCallErrorException;
use ChargerEmulator\Storage\LocalAuthorizationListRepository;

/** Applies versioned OCPP local authorization list updates. */
final class SendLocalListRequestHandler
{
    private const ID_TOKEN_TYPES = ['Central', 'eMAID', 'ISO14443', 'ISO15693', 'KeyCode', 'Local', 'MacAddress', 'NoAuthorization', 'Other'];

    private const ID_TOKEN_STATUSES = ['Accepted', 'Blocked', 'ConcurrentTx', 'Expired', 'Invalid', 'NoCredit', 'NotAllowedTypeEVSE', 'NotAtThisLocation', 'NotAtThisTime', 'Unknown'];

    public function __construct(
        private readonly int $chargerId,
        private readonly LocalAuthorizationListRepository $authorizationLists,
    ) {
    }

    public function send(array $payload): array
    {
        $versionNumber = $payload['versionNumber'] ?? null;
        $updateType = $payload['updateType'] ?? null;
        $entries = array_key_exists('localAuthorizationList', $payload) ? $payload['localAuthorizationList'] : [];
        if (array_diff(array_keys($payload), ['customData', 'versionNumber', 'updateType', 'localAuthorizationList']) !== []
            || !$this->validCustomData($payload['customData'] ?? null, array_key_exists('customData', $payload))
            || !is_int($versionNumber)
            || $versionNumber < 1
            || !in_array($updateType, ['Full', 'Differential'], true)
            || !is_array($entries)
            || !array_is_list($entries)
            || ($updateType === 'Full' && array_filter($entries, fn (mixed $entry): bool => !$this->validEntry($entry, true)) !== [])
            || ($updateType === 'Differential' && array_filter($entries, fn (mixed $entry): bool => !$this->validEntry($entry, false)) !== [])
            || !$this->hasUniqueTokens($entries)) {
            throw new OcppCallErrorException('FormationViolation', 'SendLocalList requires a valid version, update type and authorization list.');
        }

        return ['status' => $this->authorizationLists->apply($this->chargerId, $versionNumber, $updateType, $entries)];
    }

    private function validEntry(mixed $entry, bool $fullUpdate): bool
    {
        if (!is_array($entry)
            || array_diff(array_keys($entry), ['customData', 'idToken', 'idTokenInfo']) !== []
            || !$this->validCustomData($entry['customData'] ?? null, array_key_exists('customData', $entry))
            || !$this->validIdToken($entry['idToken'] ?? null)) {
            return false;
        }

        if (!array_key_exists('idTokenInfo', $entry)) {
            return !$fullUpdate;
        }

        $info = $entry['idTokenInfo'];

        return is_array($info)
            && array_diff(array_keys($info), ['customData', 'status', 'cacheExpiryDateTime', 'chargingPriority', 'language1', 'evseId', 'groupIdToken', 'language2', 'personalMessage']) === []
            && $this->validCustomData($info['customData'] ?? null, array_key_exists('customData', $info))
            && in_array($info['status'] ?? null, self::ID_TOKEN_STATUSES, true)
            && (!array_key_exists('cacheExpiryDateTime', $info) || (is_string($info['cacheExpiryDateTime']) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/', $info['cacheExpiryDateTime']) === 1 && strtotime($info['cacheExpiryDateTime']) !== false))
            && (!array_key_exists('chargingPriority', $info) || is_int($info['chargingPriority']))
            && (!array_key_exists('language1', $info) || is_string($info['language1']))
            && (!array_key_exists('language2', $info) || is_string($info['language2']))
            && (!array_key_exists('evseId', $info) || (is_array($info['evseId']) && array_is_list($info['evseId']) && count(array_filter($info['evseId'], 'is_int')) === count($info['evseId'])))
            && (!array_key_exists('groupIdToken', $info) || $this->validIdToken($info['groupIdToken']))
            && (!array_key_exists('personalMessage', $info) || $this->validPersonalMessage($info['personalMessage']));
    }

    private function validIdToken(mixed $idToken): bool
    {
        return is_array($idToken)
            && array_diff(array_keys($idToken), ['customData', 'idToken', 'type', 'additionalInfo']) === []
            && $this->validCustomData($idToken['customData'] ?? null, array_key_exists('customData', $idToken))
            && is_string($idToken['idToken'] ?? null)
            && $idToken['idToken'] !== ''
            && strlen($idToken['idToken']) <= 36
            && in_array($idToken['type'] ?? null, self::ID_TOKEN_TYPES, true)
            && (!array_key_exists('additionalInfo', $idToken) || (is_array($idToken['additionalInfo']) && array_is_list($idToken['additionalInfo']) && array_filter($idToken['additionalInfo'], fn (mixed $info): bool => !$this->validAdditionalInfo($info)) === []));
    }

    private function validAdditionalInfo(mixed $info): bool
    {
        return is_array($info)
            && array_diff(array_keys($info), ['customData', 'additionalIdToken', 'type']) === []
            && $this->validCustomData($info['customData'] ?? null, array_key_exists('customData', $info))
            && is_string($info['additionalIdToken'] ?? null)
            && $info['additionalIdToken'] !== ''
            && is_string($info['type'] ?? null)
            && $info['type'] !== '';
    }

    private function validCustomData(mixed $customData, bool $present): bool
    {
        return !$present || (is_array($customData)
            && array_diff(array_keys($customData), ['vendorId']) === []
            && is_string($customData['vendorId'] ?? null)
            && $customData['vendorId'] !== ''
            && strlen($customData['vendorId']) <= 255);
    }

    private function validPersonalMessage(mixed $message): bool
    {
        return is_array($message)
            && array_diff(array_keys($message), ['customData', 'format', 'language', 'content']) === []
            && $this->validCustomData($message['customData'] ?? null, array_key_exists('customData', $message))
            && in_array($message['format'] ?? null, ['ASCII', 'UTF8'], true)
            && (!array_key_exists('language', $message) || is_string($message['language']))
            && is_string($message['content'] ?? null);
    }

    private function hasUniqueTokens(array $entries): bool
    {
        $tokens = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_array($entry['idToken'] ?? null)) {
                continue;
            }

            $token = $entry['idToken']['type'] . "\0" . $entry['idToken']['idToken'];
            if (isset($tokens[$token])) {
                return false;
            }
            $tokens[$token] = true;
        }

        return true;
    }
}
