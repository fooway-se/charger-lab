<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use ChargerEmulator\Storage\OutletSessionRepository;
use ChargerEmulator\Storage\DeviceModelSettingsRepository;
use ChargerEmulator\Storage\AvailabilityRepository;

/**
 * Implements the OCPP Device Model variables exposed by one charger.
 *
 * It serves CSMS reads and writes from charger settings and outlet state
 * so the session worker can focus on routing protocol messages.
 */
final class DeviceModel
{
    private const OCPP_COMMUNICATION_VARIABLES = [
        'MessageAttemptsTransactionEvent',
        'MessageAttemptIntervalTransactionEvent',
        'RetryBackOffWaitMinimum',
        'RetryBackOffRandomRange',
        'RetryBackOffRepeatTimes',
        'HeartbeatInterval',
    ];

    public function __construct(
        private readonly OutletSessionRepository $outletSessions,
        private readonly DeviceModelSettingsRepository $settings,
        private readonly int $chargerId,
        private readonly AvailabilityRepository $availability,
    ) {
    }

    public function processVariableAction(string $action, array $payload): ?array
    {
        return match ($action) {
            'GetVariables' => $this->getVariables($payload),
            'SetVariables' => $this->setVariables($payload),
            default => null,
        };
    }

    private function getVariables(array $payload): array
    {
        $variableResults = [];
        foreach ($payload['getVariableData'] ?? [] as $variableRequest) {
            if (!is_array($variableRequest)) {
                continue;
            }

            $component = $this->arrayField($variableRequest, 'component');
            $variable = $this->arrayField($variableRequest, 'variable');
            $attributeType = is_string($variableRequest['attributeType'] ?? null) ? $variableRequest['attributeType'] : 'Actual';
            $attributeValue = $this->readVariableValue($component, $variable);
            $attributeStatus = $attributeValue === null ? $this->unknownVariableStatus($component) : 'Accepted';
            if ($attributeValue !== null && $attributeType !== 'Actual') {
                $attributeStatus = 'NotSupportedAttributeType';
            }

            $variableResult = [
                'attributeStatus' => $attributeStatus,
                'component' => $component,
                'variable' => $variable,
                'attributeType' => $attributeType,
            ];
            if ($attributeStatus === 'Accepted') {
                $variableResult['attributeValue'] = $attributeValue;
            }

            $variableResults[] = $variableResult;
        }

        return ['getVariableResult' => $variableResults];
    }

    private function setVariables(array $payload): array
    {
        $variableResults = [];
        foreach ($payload['setVariableData'] ?? [] as $variableRequest) {
            if (!is_array($variableRequest)) {
                continue;
            }

            $component = $this->arrayField($variableRequest, 'component');
            $variable = $this->arrayField($variableRequest, 'variable');
            $attributeType = is_string($variableRequest['attributeType'] ?? null) ? $variableRequest['attributeType'] : 'Actual';
            $currentAttributeValue = $this->readVariableValue($component, $variable);
            if ($currentAttributeValue === null) {
                $attributeStatus = $this->unknownVariableStatus($component);
            } elseif ($attributeType !== 'Actual') {
                $attributeStatus = 'NotSupportedAttributeType';
            } elseif ($this->isVariableWritable($component, $variable)) {
                $newAttributeValue = $variableRequest['attributeValue'] ?? null;
                if (is_string($newAttributeValue) && $this->isValidSettingValue($variable['name'], $newAttributeValue)) {
                    $this->settings->set($this->chargerId, $variable['name'], $newAttributeValue);
                    $attributeStatus = 'Accepted';
                } else {
                    $attributeStatus = 'Rejected';
                }
            } else {
                $attributeStatus = 'Rejected';
            }

            $variableResults[] = [
                'attributeType' => $attributeType,
                'attributeStatus' => $attributeStatus,
                'component' => $component,
                'variable' => $variable,
            ];
        }

        return ['setVariableResult' => $variableResults];
    }

    private function readVariableValue(array $component, array $variable): ?string
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

        if ($componentName === 'TxCtrlr' && $variableName === 'EVConnectionTimeOut') {
            return $this->settings->get($this->chargerId, $variableName);
        }

        if (in_array($componentName, ['AlignedDataCtrlr', 'SampledDataCtrlr'], true)) {
            return $this->settings->get($this->chargerId, $variableName);
        }

        if ($componentName === 'OCPPCommCtrlr'
            && in_array($variableName, self::OCPP_COMMUNICATION_VARIABLES, true)) {
            return $this->settings->get($this->chargerId, $variableName);
        }

        if ($variableName === 'Available' && in_array($componentName, ['ChargingStation', 'EVSE', 'Connector'], true)) {
            return $this->availabilityState($componentName, $component) === 'Unavailable' ? 'false' : 'true';
        }

        if ($variableName !== 'AvailabilityState'
            || !in_array($componentName, ['ChargingStation', 'EVSE', 'Connector'], true)) {
            return null;
        }

        return $this->availabilityState($componentName, $component);
    }

    private function arrayField(array $data, string $field): array
    {
        $value = $data[$field] ?? null;

        return is_array($value) ? $value : [];
    }

    private function availabilityState(string $componentName, array $component): ?string
    {
        $evse = $this->arrayField($component, 'evse');
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

        $states = [];
        foreach ($matchingOutlets as $outlet) {
            $state = $this->availability->connectorStatus(
                $this->chargerId,
                $outlet['evse_id'],
                $outlet['connector_id'],
                $outlet['connector_status'],
            );
            if ($state === 'Occupied') {
                return 'Occupied';
            }

            $states[] = $state;
        }

        return $states !== [] && array_all($states, static fn (string $state): bool => $state === 'Unavailable')
            ? 'Unavailable'
            : 'Available';
    }

    private function unknownVariableStatus(array $component): string
    {
        if (!$this->componentExists($component)) {
            return 'UnknownComponent';
        }

        return 'UnknownVariable';
    }

    private function componentExists(array $component): bool
    {
        $componentName = $component['name'] ?? null;
        if (!is_string($componentName)) {
            return false;
        }

        if (in_array($componentName, ['AlignedDataCtrlr', 'AuthCtrlr', 'ChargingStation', 'OCPPCommCtrlr', 'SampledDataCtrlr', 'TxCtrlr'], true)) {
            return !isset($component['instance']);
        }

        if (!in_array($componentName, ['EVSE', 'Connector'], true)) {
            return false;
        }

        $evse = $this->arrayField($component, 'evse');
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

    private function isVariableWritable(array $component, array $variable): bool
    {
        if (($component['name'] ?? null) === 'SampledDataCtrlr') {
            return in_array($variable['name'] ?? null, ['TxEndedInterval', 'TxEndedMeasurands', 'TxStartedMeasurands', 'TxUpdatedInterval', 'TxUpdatedMeasurands'], true);
        }

        if (($component['name'] ?? null) === 'AlignedDataCtrlr') {
            return in_array($variable['name'] ?? null, ['Interval', 'Measurands', 'SendDuringIdle'], true);
        }

        if (($component['name'] ?? null) === 'TxCtrlr') {
            return ($variable['name'] ?? null) === 'EVConnectionTimeOut';
        }

        return ($component['name'] ?? null) === 'OCPPCommCtrlr'
            && in_array($variable['name'] ?? null, self::OCPP_COMMUNICATION_VARIABLES, true);
    }

    private function isValidSettingValue(string $variable, string $value): bool
    {
        if (in_array($variable, [
            'Interval',
            'TxEndedInterval',
            'TxUpdatedInterval',
            ...self::OCPP_COMMUNICATION_VARIABLES,
            'EVConnectionTimeOut',
        ], true)) {
            return preg_match('/^(0|[1-9][0-9]*)$/', $value) === 1
                && filter_var($value, FILTER_VALIDATE_INT) !== false
                && (!in_array($variable, ['MessageAttemptsTransactionEvent', 'RetryBackOffWaitMinimum', 'HeartbeatInterval', 'EVConnectionTimeOut'], true) || (int) $value > 0);
        }

        if ($variable === 'SendDuringIdle') {
            return in_array($value, ['true', 'false'], true);
        }

        if (!in_array($variable, ['Measurands', 'TxEndedMeasurands', 'TxStartedMeasurands', 'TxUpdatedMeasurands'], true)) {
            return false;
        }

        if ($value === '') {
            return true;
        }

        return $value === 'Energy.Active.Import.Register';
    }
}
