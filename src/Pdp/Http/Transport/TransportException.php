<?php

declare(strict_types=1);

namespace Sapl\Pdp\Http\Transport;

use RuntimeException;

/**
 * A transport-level failure reaching the PDP (connection refused, reset,
 * timeout). The client catches this and fails closed to INDETERMINATE; it never
 * propagates to the caller.
 */
final class TransportException extends RuntimeException
{
}
