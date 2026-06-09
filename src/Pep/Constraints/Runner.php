<?php

declare(strict_types=1);

namespace Sapl\Pep\Constraints;

use Closure;

/**
 * A side effect that ignores the carried value.
 */
final class Runner implements ConstraintHandler
{
    /**
     * @param Closure(): void $run
     */
    public function __construct(
        public readonly Closure $run,
    ) {
    }
}
