<?php

declare(strict_types=1);

namespace Sapl\Doctrine\Odm;

use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\Query\Filter\BsonFilter;
use Sapl\Pep\AccessDeniedException;
use Sapl\Pep\Constraints\ActivePlan;
use Sapl\Pep\Constraints\SignalKind;
use Sapl\Pep\Present;

/**
 * Doctrine ODM filter that narrows the documents a PreEnforce-protected method
 * reads.
 *
 * Doctrine instantiates the filter with only the DocumentManager, so it cannot
 * be handed the active plan by the container. It reads the plan from
 * {@see ActivePlan} instead, set by the blocking PEP for the duration of the
 * protected invocation.
 *
 * The filter is registered always-on but inert: it contributes an empty criteria
 * array when no plan is in scope (no enforced method on the stack) or the plan
 * has no mongo:queryRewriting obligation. When it does, it discharges the
 * MONGO_QUERY signal through the plan and Doctrine AND-merges the provider's
 * rendered BSON criteria into the query. A failed obligation flips the plan's
 * failure state, and the filter fails closed by raising
 * {@see AccessDeniedException} at query time.
 */
final class SaplBsonFilter extends BsonFilter
{
    public const string FILTER_NAME = 'sapl_mongo';

    private const string ERROR_OBLIGATION_FAILED = 'Access denied: a mongo:queryRewriting obligation handler failed.';

    /**
     * @param ClassMetadata<object> $class
     *
     * @return array<string, mixed>
     */
    public function addFilterCriteria(ClassMetadata $class): array
    {
        $plan = ActivePlan::get();
        if (null === $plan || [] === $plan->entriesFor(SignalKind::MONGO_QUERY)) {
            return [];
        }

        /** @var class-string $documentClass */
        $documentClass = $class->getName();
        $request = new MongoManipulationRequest($documentClass);
        $result = $plan->execute(SignalKind::MONGO_QUERY, new Present($request), false);
        if ($result->failureState) {
            throw new AccessDeniedException(self::ERROR_OBLIGATION_FAILED);
        }

        $criteria = $result->value instanceof Present ? $result->value->value : null;
        if (!is_array($criteria)) {
            return [];
        }

        $bson = [];
        foreach ($criteria as $field => $value) {
            $bson[(string) $field] = $value;
        }

        return $bson;
    }
}
