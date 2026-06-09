<?php

declare(strict_types=1);

namespace Sapl\Pep;

/**
 * The outcome of discharging the constraint handlers for one signal: the
 * (possibly transformed or dropped) value carried through the handlers, and
 * whether an obligation handler failed.
 */
final class EnforcementResult
{
    public function __construct(
        public readonly Maybe $value,
        public readonly bool $failureState,
    ) {
    }
}
