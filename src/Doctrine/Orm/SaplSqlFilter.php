<?php

declare(strict_types=1);

namespace Sapl\Doctrine\Orm;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Sapl\Pep\AccessDeniedException;
use Sapl\Pep\Constraints\ActivePlan;
use Sapl\Pep\Constraints\SignalKind;
use Sapl\Pep\Present;

/**
 * Doctrine ORM filter that narrows the rows a PreEnforce-protected method reads.
 *
 * Doctrine instantiates the filter with only the EntityManager, so it cannot be
 * handed the active plan by the container. It reads the plan from {@see ActivePlan}
 * instead, set by the blocking PEP for the duration of the protected invocation.
 *
 * The filter is registered always-on but inert: it contributes nothing when no
 * plan is in scope (no enforced method on the stack) or the plan has no
 * sql:queryRewriting obligation. When it does, it discharges the SQL_QUERY signal
 * through the plan and AND-merges the provider's rendered predicate into the
 * query. A failed obligation flips the plan's failure state, and the filter fails
 * closed by raising {@see AccessDeniedException} at query time.
 */
final class SaplSqlFilter extends SQLFilter
{
    public const string FILTER_NAME = 'sapl_sql';

    private const string ERROR_OBLIGATION_FAILED = 'Access denied: a sql:queryRewriting obligation handler failed.';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        $plan = ActivePlan::get();
        if (null === $plan || [] === $plan->entriesFor(SignalKind::SQL_QUERY)) {
            return '';
        }

        $request = new SqlManipulationRequest($targetTableAlias);
        $result = $plan->execute(SignalKind::SQL_QUERY, new Present($request), false);
        if ($result->failureState) {
            throw new AccessDeniedException(self::ERROR_OBLIGATION_FAILED);
        }

        $predicate = $result->value instanceof Present ? $result->value->value : null;

        return is_string($predicate) ? $predicate : '';
    }
}
