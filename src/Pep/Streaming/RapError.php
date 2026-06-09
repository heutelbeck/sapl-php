<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

use Throwable;

/**
 * The protected method (or the wrapping pipeline) raised. Terminal.
 */
final class RapError implements Event
{
    public function __construct(
        public readonly Throwable $error,
    ) {
    }
}
