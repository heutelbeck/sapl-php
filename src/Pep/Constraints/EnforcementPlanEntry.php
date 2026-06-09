<?php

declare(strict_types=1);

namespace Sapl\Pep\Constraints;

use LogicException;

/**
 * A scheduled handler in an {@see EnforcementPlan}. Orders by ascending priority,
 * then by shape: Runner before Mapper before Consumer.
 */
final class EnforcementPlanEntry
{
    public function __construct(
        public readonly ConstraintHandler $handler,
        public readonly int $priority,
        public readonly ConstraintType $constraintType,
        public readonly mixed $constraint,
    ) {
    }

    public function shapeRank(): int
    {
        return match (true) {
            $this->handler instanceof Runner => 0,
            $this->handler instanceof Mapper => 1,
            $this->handler instanceof Consumer => 2,
            default => throw new LogicException('Unknown constraint handler shape'),
        };
    }
}
