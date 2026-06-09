<?php

declare(strict_types=1);

namespace Sapl\Pep\Constraints;

use Closure;

/**
 * Observes the carried value without changing it.
 */
final class Consumer implements ConstraintHandler
{
    /**
     * @param Closure(mixed): void $accept
     */
    public function __construct(
        public readonly Closure $accept,
    ) {
    }
}
