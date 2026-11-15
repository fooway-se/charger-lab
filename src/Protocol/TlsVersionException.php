<?php

declare(strict_types=1);

namespace ChargerEmulator\Protocol;

use RuntimeException;

/** Indicates that a secure CSMS endpoint negotiated a TLS version below the OCPP minimum. */
final class TlsVersionException extends RuntimeException
{
}
