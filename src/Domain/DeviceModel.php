<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use ChargerEmulator\Storage\OutletSessionRepository;
use ChargerEmulator\Storage\DeviceModelSettingsRepository;
use ChargerEmulator\Storage\AvailabilityRepository;
use Closure;

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
        'OfflineThreshold',
        'NetworkConfigurationPriority',
        'NetworkProfileConnectionAttempts',
    ];

    private const REPORT_VARIABLES = [
        'AuthCtrlr' => ['AuthorizeRemoteStart' => ['boolean', 'ReadOnly', true]],
        'SecurityCtrlr' => ['BasicAuthPassword' => ['string', 'WriteOnly', true, null, null, 64]],
        'TxCtrlr' => ['EVConnectionTimeOut' => ['integer', 'ReadWrite', true]],
        'AlignedDataCtrlr' => [
            'Interval' => ['integer', 'ReadWrite', true],
            'Measurands' => ['MemberList', 'ReadWrite', true, MeterMeasurands::SUPPORTED_LIST],
            'SendDuringIdle' => ['boolean', 'ReadWrite', true],
        ],
        'SampledDataCtrlr' => [
            'TxStartedMeasurands' => ['MemberList', 'ReadWrite', true, MeterMeasurands::SUPPORTED_LIST],
            'TxEndedInterval' => ['integer', 'ReadWrite', true],
            'TxEndedMeasurands' => ['MemberList', 'ReadWrite', true, MeterMeasurands::SUPPORTED_LIST],
            'TxUpdatedInterval' => ['integer', 'ReadWrite', true],
            'TxUpdatedMeasurands' => ['MemberList', 'ReadWrite', true, MeterMeasurands::SUPPORTED_LIST],
        ],
        'OCPPCommCtrlr' => [
            'MessageAttempts' => ['integer', 'ReadWrite', true, null, 'TransactionEvent'],
            'MessageAttemptInterval' => ['integer', 'ReadWrite', true, null, 'TransactionEvent'],
            'RetryBackOffWaitMinimum' => ['integer', 'ReadWrite', true],
            'RetryBackOffRandomRange' => ['integer', 'ReadWrite', true],
            'RetryBackOffRepeatTimes' => ['integer', 'ReadWrite', true],
            'OfflineThreshold' => ['integer', 'ReadWrite', true],
            'HeartbeatInterval' => ['integer', 'ReadWrite', true],
            'NetworkConfigurationPriority' => ['MemberList', 'ReadWrite', true, '0,1,2'],
            'NetworkProfileConnectionAttempts' => ['integer', 'ReadWrite', true],
        ],
    ];

    public function __construct(
        private readonly OutletSessionRepository $outletSessions,
        private readonly DeviceModelSettingsRepository $settings,
        private readonly int $chargerId,
        private readonly AvailabilityRepository $availability,
        private readonly ?Closure $basicAuthPasswordWriter = null,
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

    public function inventory(string $reportBase): array
    {
        $report = [];
        if ($reportBase !== 'ConfigurationInventory') {
            foreach ($this->physicalComponents() as $component) {
                $report[] = $this->reportVariable($component, ['name' => 'AvailabilityState'], 'OptionList', 'ReadOnly', false, false, 'Available,Occupied,Unavailable');
                if ($reportBase === 'FullInventory') {
                    $report[] = $this->reportVariable($component, ['name' => 'Available'], 'boolean', 'ReadOnly', false, false);
                }
            }
        }

        if ($reportBase !== 'SummaryInventory') {
            foreach (self::REPORT_VARIABLES as $componentName => $variables) {
                foreach ($variables as $variableName => $definition) {
                    [$dataType, $mutability, $persistent] = $definition;
                    if ($reportBase === 'ConfigurationInventory' && $mutability === 'ReadOnly') {
                        continue;
                    }

                    $report[] = $this->reportVariable(
                        ['name' => $componentName],
                        ['name' => $variableName, ...(($definition[4] ?? null) === null ? [] : ['instance' => $definition[4]])],
                        $dataType,
                        $mutability,
                        $persistent,
                        $componentName === 'AuthCtrlr',
                        $definition[3] ?? null,
                        $definition[5] ?? null,
                    );
                }
            }
        }

        return $report;
    }

    private function physicalComponents(): array
    {
        $components = [['name' => 'ChargingStation']];
        $reportedEvses = [];
        foreach ($this->outletSessions->allForCharger($this->chargerId) as $outlet) {
            $evse = ['id' => $outlet['evse_id']];
            if (!isset($reportedEvses[$outlet['evse_id']])) {
                $components[] = ['name' => 'EVSE', 'evse' => $evse];
                $reportedEvses[$outlet['evse_id']] = true;
            }
            $components[] = ['name' => 'Connector', 'evse' => [...$evse, 'connectorId' => $outlet['connector_id']]];
        }

        return $components;
    }

    private function reportVariable(
        array $component,
        array $variable,
        string $dataType,
        string $mutability,
        bool $persistent,
        bool $constant,
        ?string $valuesList = null,
        ?int $maxLimit = null,
    ): array
    {
        $value = $this->readVariableValue($component, $variable);
        $variableAttribute = [
            'type' => 'Actual',
            ...($mutability === 'WriteOnly' ? [] : ['value' => $value]),
            'mutability' => $mutability,
            'persistent' => $persistent,
            'constant' => $constant,
        ];

        return [
            'component' => $component,
            'variable' => $variable,
            'variableAttribute' => [$variableAttribute],
            'variableCharacteristics' => [
                'dataType' => $dataType,
                ...($valuesList === null ? [] : ['valuesList' => $valuesList]),
                'supportsMonitoring' => false,
                ...($maxLimit === null ? [] : ['maxLimit' => $maxLimit]),
            ],
        ];
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
            $attributeStatus = $attributeValue === null
                ? ($this->isWriteOnlyVariable($component, $variable) ? 'Rejected' : $this->unknownVariableStatus($component))
                : 'Accepted';
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
            $settingName = $this->settingName($component, $variable);
            $currentAttributeValue = $this->readVariableValue($component, $variable);
            $isBasicAuthPassword = $this->isBasicAuthPassword($component, $variable);
            if ($currentAttributeValue === null && !$isBasicAuthPassword) {
                $attributeStatus = $this->unknownVariableStatus($component);
            } elseif ($attributeType !== 'Actual') {
                $attributeStatus = 'NotSupportedAttributeType';
            } elseif ($this->isVariableWritable($component, $variable)) {
                $newAttributeValue = $variableRequest['attributeValue'] ?? null;
                if (is_string($newAttributeValue)
                    && ($isBasicAuthPassword
                        ? $this->isValidBasicAuthPassword($newAttributeValue) && $this->basicAuthPasswordWriter !== null
                        : $this->isValidSettingValue($settingName, $newAttributeValue))) {
                    if ($isBasicAuthPassword) {
                        ($this->basicAuthPasswordWriter)($newAttributeValue);
                    } else {
                        $this->settings->set($this->chargerId, $settingName, $newAttributeValue);
                    }
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

        if ($this->isBasicAuthPassword($component, $variable)) {
            return null;
        }

        if ($componentName === 'TxCtrlr' && $variableName === 'EVConnectionTimeOut') {
            return $this->settings->get($this->chargerId, $variableName);
        }

        if (in_array($componentName, ['AlignedDataCtrlr', 'SampledDataCtrlr'], true)) {
            return $this->settings->get($this->chargerId, $variableName);
        }

        $settingName = $this->settingName($component, $variable);
        if ($componentName === 'OCPPCommCtrlr'
            && in_array($settingName, self::OCPP_COMMUNICATION_VARIABLES, true)) {
            return $this->settings->get($this->chargerId, $settingName);
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

        if (in_array($componentName, ['AlignedDataCtrlr', 'AuthCtrlr', 'ChargingStation', 'OCPPCommCtrlr', 'SampledDataCtrlr', 'SecurityCtrlr', 'TxCtrlr'], true)) {
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
        if ($this->isBasicAuthPassword($component, $variable)) {
            return true;
        }

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
            && in_array($this->settingName($component, $variable), self::OCPP_COMMUNICATION_VARIABLES, true);
    }

    private function settingName(array $component, array $variable): string
    {
        $name = $variable['name'] ?? '';
        if (($component['name'] ?? null) === 'OCPPCommCtrlr'
            && ($variable['instance'] ?? null) === 'TransactionEvent') {
            return match ($name) {
                'MessageAttempts' => 'MessageAttemptsTransactionEvent',
                'MessageAttemptInterval' => 'MessageAttemptIntervalTransactionEvent',
                default => $name,
            };
        }

        return $name;
    }

    private function isWriteOnlyVariable(array $component, array $variable): bool
    {
        return $this->isBasicAuthPassword($component, $variable);
    }

    private function isBasicAuthPassword(array $component, array $variable): bool
    {
        return ($component['name'] ?? null) === 'SecurityCtrlr'
            && !isset($component['instance'])
            && ($variable['name'] ?? null) === 'BasicAuthPassword';
    }

    private function isValidBasicAuthPassword(string $password): bool
    {
        if (strlen($password) < 16 || strlen($password) > 64) {
            return false;
        }

        $allowedCharacters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789*-_-=:+|@.';

        return strspn($password, $allowedCharacters) === strlen($password);
    }

    private function isValidSettingValue(string $variable, string $value): bool
    {
        if ($variable === 'NetworkConfigurationPriority') {
            $slots = explode(',', $value);

            return $slots !== ['']
                && count($slots) === count(array_unique($slots))
                && array_all($slots, static fn (string $slot): bool => in_array($slot, ['0', '1', '2'], true));
        }

        if (in_array($variable, [
            'Interval',
            'TxEndedInterval',
            'TxUpdatedInterval',
            ...self::OCPP_COMMUNICATION_VARIABLES,
            'EVConnectionTimeOut',
        ], true)) {
            return preg_match('/^(0|[1-9][0-9]*)$/', $value) === 1
                && filter_var($value, FILTER_VALIDATE_INT) !== false
                && (!in_array($variable, ['MessageAttemptsTransactionEvent', 'RetryBackOffWaitMinimum', 'HeartbeatInterval', 'NetworkProfileConnectionAttempts', 'EVConnectionTimeOut'], true) || (int) $value > 0);
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

        return MeterMeasurands::parse($value) !== null;
    }
}
