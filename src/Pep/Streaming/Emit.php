<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

/**
 * Deliver the value to the subscriber.
 */
final class Emit implements Emission
{
    public function __construct(
        public readonly mixed $value,
    ) {
    }
}
