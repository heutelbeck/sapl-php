<?php

declare(strict_types=1);

namespace Sapl\Pep;

use Closure;

/**
 * The invocation of a protected method: its arguments and the means to proceed.
 *
 * Input-signal mappers may mutate {@see $arguments} in place before the method
 * runs (mirroring the reference's mutable MethodInvocation); {@see proceed()}
 * then calls the method with the current arguments.
 */
final class MethodInvocation
{
    /**
     * @param list<mixed>                 $arguments
     * @param Closure(list<mixed>): mixed $proceed
     */
    public function __construct(
        public array $arguments,
        private readonly Closure $proceed,
    ) {
    }

    public function proceed(): mixed
    {
        return ($this->proceed)($this->arguments);
    }
}
