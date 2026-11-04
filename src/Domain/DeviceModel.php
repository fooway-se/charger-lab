<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use ChargerEmulator\Storage\OutletSessionRepository;
use ChargerEmulator\Storage\DeviceModelSettingsRepository;

/**
 * Answers the supported OCPP Device Model variables for one charger.
 */
final class DeviceModel
{
    public function __construct(
        private readonly OutletSessionRepository $outletSessions,
        private readonly DeviceModelSettingsRepository $settings,
        private readonly int $chargerId,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function handle(string $action, array $payload): ?array
    {
        return match ($action) {
            'GetVariables' => $this->getVariables($payload),
            'SetVariables' => $this->setVariables($payload),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{getVariableResult:list<array<string, mixed>>}
     */
    private function getVariables(array $payload): array
    {
        $results = [];
        foreach ($payload['getVariableData'] ?? [] as $request) {
            if (!is_array($request)) {
                continue;
            }

            $component = is_array($request['component'] ?? null) ? $request['component'] : [];
            $variable = is_array($request['variable'] ?? null) ? $request['variable'] : [];
            $attributeType = is_string($request['attributeType'] ?? null) ? $request['attributeType'] : 'Actual';
            $value = $this->readValue($component, $variable);
            $status = $value === null ? $this->unknownStatus($component) : 'Accepted';
            if ($value !== null && $attributeType !== 'Actual') {
                $status = 'NotSupportedAttributeType';
            }

            $result = [
                'attributeStatus' => $status,
                'component' => $component,
                'variable' => $variable,
                'attributeType' => $attributeType,
            ];
            if ($status === 'Accepted') {
                $result['attributeValue'] = $value;
            }

            $results[] = $result;
        }

        return ['getVariableResult' => $results];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{setVariableResult:list<array<string, mixed>>}
     */
    private function setVariables(array $payload): array
    {
        $results = [];
        foreach ($payload['setVariableData'] ?? [] as $request) {
            if (!is_array($request)) {
                continue;
            }

            $component = is_array($request['component'] ?? null) ? $request['component'] : [];
            $variable = is_array($request['variable'] ?? null) ? $request['variable'] : [];
            $attributeType = is_string($request['attributeType'] ?? null) ? $request['attributeType'] : 'Actual';
            $knownValue = $this->readValue($component, $variable);
            if ($knownValue === null) {
                $status = $this->unknownStatus($component);
            } elseif ($attributeType !== 'Actual') {
                $status = 'NotSupportedAttributeType';
            } elseif ($this->isWritable($component, $variable)) {
                $value = $request['attributeValue'] ?? null;
                if (is_string($value) && $this->isValidSetting($variable['name'], $value)) {
                    $this->settings->set($this->chargerId, $variable['name'], $value);
                    $status = 'Accepted';
                } else {
                    $status = 'Rejected';
                }
            } else {
                $status = 'Rejected';
            }

            $results[] = [
                'attributeType' => $attributeType,
                'attributeStatus' => $status,
                'component' => $component,
                'variable' => $variable,
            ];
        }

        return ['setVariableResult' => $results];
    }

    /**
     * @param array<string, mixed> $component
     * @param array<string, mixed> $variable
     */
    private function readValue(array $component, array $variable): ?string
    {
        $componentName = $component['name'] ?? null;
        $variableName = $variable['name'] ?? null;
        if (!is_string($componentName) || !is_string($variableName)) {
            return null;
        }

        if (!$this->componentExists($component)) {
            return null;
        }

        if ($componentName === 'AuthCtrlr') {
            return match ($variableName) {
                'AuthorizeRemoteStart' => 'false',
                default => null,
            };
        }

        if ($componentName === 'SampledDataCtrlr') {
            return $this->settings->get($this->chargerId, $variableName);
        }

        if ($variableName === 'Available' && in_array($componentName, ['ChargingStation', 'EVSE', 'Connector'], true)) {
            return 'true';
        }

        if ($variableName !== 'AvailabilityState'
            || !in_array($componentName, ['ChargingStation', 'EVSE', 'Connector'], true)) {
            return null;
        }

        return $this->availabilityState($componentName, $component);
    }

    /**
     * @param array<string, mixed> $component
     */
    private function availabilityState(string $componentName, array $component): ?string
    {
        $evse = is_array($component['evse'] ?? null) ? $component['evse'] : [];
        $evseId = $evse['id'] ?? null;
        $connectorId = $evse['connectorId'] ?? null;
        $outlets = $this->outletSessions->allForCharger($this->chargerId);
        $matchingOutlets = array_values(array_filter($outlets, static function (array $outlet) use ($componentName, $evseId, $connectorId): bool {
            if ($componentName === 'ChargingStation') {
                return true;
            }

            if ($outlet['evse_id'] !== $evseId) {
                return false;
            }

            return $componentName !== 'Connector' || $outlet['connector_id'] === $connectorId;
        }));
        if ($matchingOutlets === []) {
            return null;
        }

        foreach ($matchingOutlets as $outlet) {
            if ($outlet['connector_status'] === 'Occupied') {
                return 'Occupied';
            }
        }

        return 'Available';
    }

    /**
     * @param array<string, mixed> $component
     * @param array<string, mixed> $variable
     */
    private function unknownStatus(array $component): string
    {
        if (!$this->componentExists($component)) {
            return 'UnknownComponent';
        }

        return 'UnknownVariable';
    }

    /**
     * @param array<string, mixed> $component
     */
    private function componentExists(array $component): bool
    {
        $componentName = $component['name'] ?? null;
        if (!is_string($componentName)) {
            return false;
        }

        if (in_array($componentName, ['AuthCtrlr', 'ChargingStation', 'SampledDataCtrlr'], true)) {
            return !isset($component['instance']);
        }

        if (!in_array($componentName, ['EVSE', 'Connector'], true)) {
            return false;
        }

        $evse = is_array($component['evse'] ?? null) ? $component['evse'] : [];
        $evseId = $evse['id'] ?? null;
        $connectorId = $evse['connectorId'] ?? null;
        if (!is_int($evseId) || $evseId < 1) {
            return false;
        }

        foreach ($this->outletSessions->allForCharger($this->chargerId) as $outlet) {
            if ($outlet['evse_id'] !== $evseId) {
                continue;
            }

            if ($componentName === 'EVSE' || $outlet['connector_id'] === $connectorId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $component
     * @param array<string, mixed> $variable
     * @return bool
     */
    private function isWritable(array $component, array $variable): bool
    {
        return ($component['name'] ?? null) === 'SampledDataCtrlr'
            && in_array($variable['name'] ?? null, ['TxStartedMeasurands', 'TxUpdatedInterval', 'TxUpdatedMeasurands'], true);
    }

    /**
     * @return bool
     */
    private function isValidSetting(string $variable, string $value): bool
    {
        if ($variable === 'TxUpdatedInterval') {
            return preg_match('/^(0|[1-9][0-9]*)$/', $value) === 1
                && filter_var($value, FILTER_VALIDATE_INT) !== false;
        }

        if (!in_array($variable, ['TxStartedMeasurands', 'TxUpdatedMeasurands'], true)) {
            return false;
        }

        if ($value === '') {
            return true;
        }

        return $value === 'Energy.Active.Import.Register';
    }
}
