<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use ChargerEmulator\Protocol\OcppCallErrorException;
use ChargerEmulator\Storage\LocalAuthorizationListRepository;

/** Reports authorization-list token information and acknowledges privacy clear requests. */
final class CustomerInformationRequestHandler
{
    private const ID_TOKEN_TYPES = ['Central', 'eMAID', 'ISO14443', 'ISO15693', 'KeyCode', 'Local', 'MacAddress', 'NoAuthorization', 'Other'];

    private array $notifications = [];
    private bool $clearRequested = false;
    private bool $reportRequested = false;
    private ?array $tokenToClear = null;

    public function __construct(
        private readonly int $chargerId,
        private readonly LocalAuthorizationListRepository $authorizationLists,
    ) {
    }

    public function request(array $payload): array
    {
        $allowed = ['customData', 'customerCertificate', 'customerIdentifier', 'idToken', 'requestId', 'report', 'clear'];
        if (array_diff(array_keys($payload), $allowed) !== []
            || !CustomDataValidator::isValid($payload['customData'] ?? null, array_key_exists('customData', $payload))
            || !is_int($payload['requestId'] ?? null) || $payload['requestId'] < 0 || $payload['requestId'] > 2_147_483_647
            || !is_bool($payload['report'] ?? null) || !is_bool($payload['clear'] ?? null)
            || (array_key_exists('idToken', $payload) && !$this->validIdToken($payload['idToken']))
            || (array_key_exists('customerIdentifier', $payload)
                && (!is_string($payload['customerIdentifier']) || $payload['customerIdentifier'] === '' || strlen($payload['customerIdentifier']) > 64))
            || (array_key_exists('customerCertificate', $payload) && !$this->validCertificateHash($payload['customerCertificate']))) {
            throw new OcppCallErrorException('FormationViolation', 'CustomerInformation requires a valid request ID, report and clear flags.');
        }

        $this->notifications = [];
        $this->clearRequested = false;
        $this->reportRequested = false;
        $this->tokenToClear = null;
        if (array_key_exists('customerIdentifier', $payload) || array_key_exists('customerCertificate', $payload)) {
            return ['status' => 'Rejected'];
        }

        $this->clearRequested = $payload['clear'];
        $this->reportRequested = $payload['report'];
        $this->tokenToClear = $payload['idToken'] ?? null;
        if (($payload['report'] ?? false) === true) {
            $data = $this->customerInformationData($payload['idToken'] ?? null);
            $this->queueNotifications((int) $payload['requestId'], $data);
        }

        return ['status' => 'Accepted'];
    }

    /** Complete a clear request after its CALLRESULT when no report was requested. */
    public function responseSent(): void
    {
        if ($this->clearRequested && !$this->reportRequested) {
            $this->clearRequested = false;
            $this->authorizationLists->clearCustomerInformation($this->chargerId, $this->tokenToClear);
        }
    }

    /** Complete a clear request only after all requested customer data was delivered. */
    public function notificationsSent(): void
    {
        if ($this->clearRequested && $this->reportRequested) {
            $this->clearRequested = false;
            $this->authorizationLists->clearCustomerInformation($this->chargerId, $this->tokenToClear);
        }
    }

    public function takeNotifications(): array
    {
        $notifications = $this->notifications;
        $this->notifications = [];

        return $notifications;
    }

    private function customerInformationData(?array $requestedToken): string
    {
        $entries = array_values(array_filter(
            $this->authorizationLists->entries($this->chargerId),
            static function (array $entry) use ($requestedToken): bool {
                if ($requestedToken === null) {
                    return true;
                }

                $entryToken = $entry['idToken'] ?? [];

                return ($entryToken['idToken'] ?? null) === ($requestedToken['idToken'] ?? null)
                    && ($entryToken['type'] ?? null) === ($requestedToken['type'] ?? null);
            },
        ));
        if ($entries === []) {
            return '';
        }

        $information = array_map(static fn (array $entry): array => array_filter([
            'idToken' => $entry['idToken'] ?? null,
            'idTokenInfo' => $entry['idTokenInfo'] ?? null,
        ], static fn (mixed $value): bool => $value !== null), $entries);

        return json_encode($information, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function queueNotifications(int $requestId, string $data): void
    {
        $chunks = $data === '' ? [''] : str_split($data, 512);
        $generatedAt = gmdate(DATE_ATOM);
        foreach ($chunks as $sequence => $chunk) {
            $this->notifications[] = [
                'data' => $chunk,
                'tbc' => $sequence < count($chunks) - 1,
                'seqNo' => $sequence,
                'generatedAt' => $generatedAt,
                'requestId' => $requestId,
            ];
        }
    }

    private function validIdToken(mixed $idToken): bool
    {
        return is_array($idToken)
            && array_diff(array_keys($idToken), ['additionalInfo', 'customData', 'idToken', 'type']) === []
            && CustomDataValidator::isValid($idToken['customData'] ?? null, array_key_exists('customData', $idToken))
            && is_string($idToken['idToken'] ?? null)
            && $idToken['idToken'] !== ''
            && strlen($idToken['idToken']) <= 36
            && in_array($idToken['type'] ?? null, self::ID_TOKEN_TYPES, true)
            && (!array_key_exists('additionalInfo', $idToken) || is_array($idToken['additionalInfo']));
    }

    private function validCertificateHash(mixed $certificateHash): bool
    {
        return is_array($certificateHash)
            && array_diff(array_keys($certificateHash), ['customData', 'hashAlgorithm', 'issuerKeyHash', 'issuerNameHash', 'serialNumber']) === []
            && CustomDataValidator::isValid($certificateHash['customData'] ?? null, array_key_exists('customData', $certificateHash))
            && in_array($certificateHash['hashAlgorithm'] ?? null, ['SHA256', 'SHA384', 'SHA512'], true)
            && is_string($certificateHash['issuerNameHash'] ?? null)
            && is_string($certificateHash['issuerKeyHash'] ?? null)
            && is_string($certificateHash['serialNumber'] ?? null);
    }
}
