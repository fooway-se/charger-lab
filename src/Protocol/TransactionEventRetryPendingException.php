<?php

declare(strict_types=1);

namespace ChargerEmulator\Protocol;

use RuntimeException;

/**
 * Indicates a queued TransactionEvent is waiting for its configured retry time.
 */
final class TransactionEventRetryPendingException extends RuntimeException
{
}
