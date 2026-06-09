<?php

declare(strict_types=1);

namespace Sapl\Pep\Constraints;

use Closure;

/**
 * Transforms the carried value.
 */
final class Mapper implements ConstraintHandler
{
    /**
     * @param Closure(mixed): mixed $apply
     */
    public function __construct(
        public readonly Closure $apply,
    ) {
    }
}
