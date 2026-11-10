<?php

declare(strict_types=1);

namespace ChargerEmulator\Protocol;

use RuntimeException;

final class OcppCallErrorException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $description,
        public readonly array|\stdClass $errorDetails = new \stdClass(),
    ) {
        parent::__construct($description);
    }
}
