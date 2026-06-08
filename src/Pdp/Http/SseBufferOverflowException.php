<?php

declare(strict_types=1);

namespace Sapl\Pdp\Http;

use RuntimeException;

/**
 * Raised when an SSE buffer exceeds its cap, which aborts the connection so the
 * stream reconnects rather than growing memory against a misbehaving PDP.
 */
final class SseBufferOverflowException extends RuntimeException
{
}
