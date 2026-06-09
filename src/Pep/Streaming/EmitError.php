<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

use Throwable;

/**
 * Terminate the subscriber with an error.
 */
final class EmitError implements Emission
{
    public function __construct(
        public readonly Throwable $error,
    ) {
    }
}
