<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use ChargerEmulator\Storage\ExchangeRepository;

/** Owns active GetLog requests, retries, cancellation and LogStatusNotification payloads. */
final class LogRetrievalManager
{
    private array $jobs = [];

    private array $notifications = [];

    public function __construct(
        private readonly ExchangeRepository $logs,
        private readonly LogUploader $uploader = new LogFileUploader(),
        private readonly GetLogRequestHandler $requestHandler = new GetLogRequestHandler(),
    ) {
    }

    public function request(int $chargerId, array $payload): array
    {
        $response = $this->requestHandler->handle($payload);
        if (($response['status'] ?? null) !== 'Accepted') {
            return $response;
        }

        $requestId = (int) $payload['requestId'];
        $previousJob = $this->jobs[$chargerId] ?? null;
        if (is_array($previousJob)) {
            $this->notifications[$chargerId][] = ['requestId' => $previousJob['request_id'], 'status' => 'AcceptedCanceled'];
            $response['status'] = 'AcceptedCanceled';
        }

        $log = $payload['log'];
        $entries = $this->logs->forLogUpload(
            $chargerId,
            $payload['logType'],
            $log['oldestTimestamp'] ?? null,
            $log['latestTimestamp'] ?? null,
        );
        $contents = implode("\n", array_map(
            static fn (array $entry): string => json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $entries,
        ));
        $this->jobs[$chargerId] = [
            'request_id' => $requestId,
            'remote_location' => $log['remoteLocation'],
            'filename' => $response['filename'],
            'contents' => $contents . ($contents === '' ? '' : "\n"),
            'retries' => $payload['retries'] ?? 0,
            'retry_interval' => $payload['retryInterval'] ?? 0,
            'attempts' => 0,
            'next_attempt_at' => null,
        ];

        return $response;
    }

    public function acceptedResponse(int $chargerId, int $requestId): void
    {
        $job = $this->jobs[$chargerId] ?? null;
        if (!is_array($job) || $job['request_id'] !== $requestId) {
            return;
        }

        $this->notifications[$chargerId][] = ['requestId' => $requestId, 'status' => 'Uploading'];
        $this->jobs[$chargerId]['next_attempt_at'] = microtime(true);
    }

    public function takeNextNotification(int $chargerId): ?array
    {
        if (($this->notifications[$chargerId] ?? []) === []) {
            return null;
        }

        return array_shift($this->notifications[$chargerId]);
    }

    public function uploadWhenDue(int $chargerId): bool
    {
        $job = $this->jobs[$chargerId] ?? null;
        if (!is_array($job) || !is_float($job['next_attempt_at']) || microtime(true) < $job['next_attempt_at']) {
            return false;
        }

        $this->jobs[$chargerId]['attempts']++;
        $uploaded = $this->uploader->upload($job['remote_location'], $job['filename'], $job['contents']);
        if ($uploaded) {
            $this->notifications[$chargerId][] = ['requestId' => $job['request_id'], 'status' => 'Uploaded'];
            unset($this->jobs[$chargerId]);

            return true;
        }

        if ($this->jobs[$chargerId]['attempts'] <= $job['retries']) {
            $this->jobs[$chargerId]['next_attempt_at'] = microtime(true) + $job['retry_interval'];

            return true;
        }

        $this->notifications[$chargerId][] = ['requestId' => $job['request_id'], 'status' => 'UploadFailure'];
        unset($this->jobs[$chargerId]);

        return true;
    }
}
