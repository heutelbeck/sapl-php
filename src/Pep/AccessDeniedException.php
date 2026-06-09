<?php

declare(strict_types=1);

namespace Sapl\Pep;

use RuntimeException;

/**
 * Access is denied by enforcement: a DENY/INDETERMINATE/NOT_APPLICABLE decision,
 * a SUSPEND in a one-shot context, or an obligation handler that failed. The
 * Symfony layer maps this to an HTTP 403.
 */
final class AccessDeniedException extends RuntimeException
{
}
