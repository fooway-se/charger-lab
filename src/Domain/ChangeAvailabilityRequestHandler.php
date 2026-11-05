<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use ChargerEmulator\Storage\AvailabilityRepository;

/**
 * Validates and applies CSMS requests to change charger availability.
 */
final class ChangeAvailabilityRequestHandler
{
    /**
     * @var list<array{evse_id:int,connector_id:int,status:string}>
     */
    private array $statusChanges = [];

    public function __construct(
        private readonly int $chargerId,
        private readonly AvailabilityRepository $availability,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status:string}
     */
    public function handle(array $payload): array
    {
        $result = $this->availability->requestChange($this->chargerId, $payload);
        $this->statusChanges = $result['changes'];

        return ['status' => $result['status']];
    }

    /**
     * @return list<array{evse_id:int,connector_id:int,status:string}>
     */
    public function takeStatusChanges(): array
    {
        $changes = $this->statusChanges;
        $this->statusChanges = [];

        return $changes;
    }
}
