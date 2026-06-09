<?php

declare(strict_types=1);

namespace Sapl\Pep;

use RuntimeException;

/**
 * A non-terminal grant boundary surfaced on the error channel of a streaming
 * subscription when transition signalling is enabled (initial grant or resume
 * from suspended). It is informational, not a denial.
 */
final class AccessGrantedException extends RuntimeException
{
}
