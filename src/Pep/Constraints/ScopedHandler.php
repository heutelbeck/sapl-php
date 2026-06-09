<?php

declare(strict_types=1);

namespace Sapl\Pep\Constraints;

/**
 * Pairs a {@see ConstraintHandler} with the signal it applies to and a sort
 * priority (lower runs first).
 */
final class ScopedHandler
{
    public function __construct(
        public readonly ConstraintHandler $handler,
        public readonly SignalKind $signal,
        public readonly int $priority,
    ) {
    }
}
