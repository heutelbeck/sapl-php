<?php

declare(strict_types=1);

namespace Sapl\Doctrine\Orm;

/**
 * The value the SQL shim discharges through the enforcement plan when a Doctrine
 * filter fires.
 *
 * Doctrine calls the filter once per root entity, join, and subquery, passing the
 * table alias each predicate must be scoped to. The provider's mapper reads that
 * alias to prefix the columns of the narrowing predicate it renders.
 */
final class SqlManipulationRequest
{
    public function __construct(
        public readonly string $alias,
    ) {
    }
}
