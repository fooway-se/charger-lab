<?php

declare(strict_types=1);

namespace ChargerEmulator\Protocol;

use ChargerEmulator\Storage\DeviceModelSettingsRepository;
use ChargerEmulator\Storage\OutletSessionRepository;
use ChargerEmulator\Storage\OfflineStatusRepository;
use ChargerEmulator\Storage\TransactionEventOutboxRepository;
use Closure;
use Throwable;

/**
 * Delivers persisted transaction events in order and records failures for retry.
 */
final class TransactionEventDelivery
{
    /**
     * @var Closure(int): OcppClient
     */
    private readonly Closure $ocppClientFactory;

    public function __construct(
        private readonly TransactionEventOutboxRepository $outbox,
        private readonly OutletSessionRepository $outletSessions,
        private readonly DeviceModelSettingsRepository $settings,
        Closure $ocppClientFactory,
        private readonly ?OfflineStatusRepository $offlineStatuses = null,
    ) {
        $this->ocppClientFactory = $ocppClientFactory;
    }

    public function enqueue(int $chargerId, array $payload): array
    {
        if ($this->offlineStatuses?->pending($chargerId) !== null) {
            $payload['offline'] = true;
        }

        return $this->outbox->enqueue($chargerId, $payload);
    }

    public function enqueueForActiveTransaction(
        int $chargerId,
        array $payload,
        int $evseId,
        int $connectorId,
        string $transactionId,
        int $previousSequence,
    ): array {
        return $this->outbox->enqueueForActiveTransaction(
            $chargerId,
            $payload,
            $this->outletSessions,
            $evseId,
            $connectorId,
            $transactionId,
            $previousSequence,
        );
    }

    public function deliverPending(int $chargerId): bool
    {
        $queuedEvent = $this->outbox->nextPending($chargerId);
        if ($queuedEvent === null) {
            return false;
        }

        $this->deliverThrough($chargerId, $queuedEvent['id']);

        return true;
    }

    public function deliverThrough(int $chargerId, int $targetId): array
    {
        $targetResponse = null;
        while (($queuedEvent = $this->outbox->nextPending($chargerId)) !== null) {
            try {
                $response = ($this->ocppClientFactory)($chargerId)->request(
                    'TransactionEvent',
                    $queuedEvent['payload'],
                    $queuedEvent['message_id'],
                );
                $this->outbox->markDelivered($queuedEvent['id']);
                if ($queuedEvent['id'] === $targetId) {
                    $targetResponse = $response;
                }
            } catch (Throwable $exception) {
                $this->outbox->recordFailure(
                    $queuedEvent['id'],
                    $exception->getMessage(),
                    $this->maxAttempts($chargerId),
                    $this->retryIntervalSeconds($chargerId),
                );
                throw $exception;
            }
        }

        if ($targetResponse === null) {
            throw new TransactionEventRetryPendingException('The transaction event is queued until its retry interval expires.');
        }

        return $targetResponse;
    }

    private function maxAttempts(int $chargerId): int
    {
        return $this->settings->getInteger($chargerId, 'MessageAttemptsTransactionEvent', 3, 1);
    }

    private function retryIntervalSeconds(int $chargerId): int
    {
        return $this->settings->getInteger($chargerId, 'MessageAttemptIntervalTransactionEvent', 60, 0);
    }
}
