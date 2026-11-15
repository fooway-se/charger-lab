<?php

declare(strict_types=1);

namespace ChargerEmulator\Domain;

use RuntimeException;

final class FirmwareSignatureException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $securityEventType = 'InvalidFirmwareSignature',
    ) {
        parent::__construct($message);
    }
}
