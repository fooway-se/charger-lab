<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

/**
 * Builds the supported full Device Model inventory report.
 */
final class BaseReportRequestHandler
{
    private ?array $report = null;

    public function __construct(private readonly DeviceModel $deviceModel)
    {
    }

    public function getBaseReport(array $payload): array
    {
        $requestId = $payload['requestId'] ?? null;
        if (!is_int($requestId) || $requestId < 0) {
            return ['status' => 'Rejected'];
        }

        $reportBase = $payload['reportBase'] ?? null;
        if (!is_string($reportBase)
            || !in_array($reportBase, ['FullInventory', 'ConfigurationInventory', 'SummaryInventory'], true)) {
            return ['status' => 'NotImplemented'];
        }

        $this->report = [
            'requestId' => $requestId,
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'tbc' => false,
            'seqNo' => 0,
            'reportData' => $this->deviceModel->inventory($reportBase),
        ];

        return ['status' => 'Accepted'];
    }

    public function takeReport(): ?array
    {
        $report = $this->report;
        $this->report = null;

        return $report;
    }
}
