<?php

declare(strict_types=1);

namespace Sapl\Pep\Constraints;

/**
 * A constraint handler in one of three shapes: {@see Runner} (a side effect that
 * ignores the value), {@see Consumer} (observes the value), {@see Mapper}
 * (transforms the value). Handlers operate on the carried value; providers cast
 * at their own boundary.
 */
interface ConstraintHandler
{
}
