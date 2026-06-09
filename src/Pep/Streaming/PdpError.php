<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

use Throwable;

/**
 * The PDP's decision stream raised. Terminal.
 */
final class PdpError implements Event
{
    public function __construct(
        public readonly Throwable $error,
    ) {
    }
}
