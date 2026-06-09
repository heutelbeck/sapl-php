<?php

declare(strict_types=1);

namespace Sapl\Pep\Constraints\Providers;

use Sapl\Doctrine\Odm\BsonCriteriaRenderer;
use Sapl\Pep\AccessDeniedException;
use Sapl\Pep\Constraints\ConstraintGuards;
use Sapl\Pep\Constraints\ConstraintHandlerProvider;
use Sapl\Pep\Constraints\Mapper;
use Sapl\Pep\Constraints\ScopedHandler;
use Sapl\Pep\Constraints\SignalKind;

/**
 * Translates a mongo:queryRewriting obligation into a mapper on the MONGO_QUERY
 * signal that renders narrowing BSON criteria for the document the Doctrine ODM
 * filter is scoping.
 *
 * The obligation narrows via a typed `criteria` tree and a string `conditions`
 * array of strict-JSON Mongo fragments, AND-combined under a top-level $and.
 * Doctrine ODM AND-merges the rendered criteria across the documents the query
 * touches, so the obligation can only narrow access, never widen it.
 *
 * Doctrine ODM filters apply to find and reference loads, not to aggregation
 * pipelines, so a mongo:queryRewriting obligation does not narrow an aggregation.
 */
final class MongoQueryRewritingProvider implements ConstraintHandlerProvider
{
    private const string CONSTRAINT_TYPE = 'mongo:queryRewriting';
    private const int PRIORITY = 30;

    private const string FIELD_CONDITIONS = 'conditions';
    private const string FIELD_CRITERIA = 'criteria';

    private const string ERROR_NON_STRING_CONDITION = 'A condition in the obligation is not a string.';

    public function getConstraintHandlers(mixed $constraint, array $supportedSignals): array
    {
        if (!ConstraintGuards::isOfType($constraint, self::CONSTRAINT_TYPE)) {
            return [];
        }
        if (!ConstraintGuards::supports($supportedSignals, SignalKind::MONGO_QUERY)) {
            return [];
        }

        $criteria = ConstraintGuards::listField($constraint, self::FIELD_CRITERIA) ?? [];
        $conditions = ConstraintGuards::listField($constraint, self::FIELD_CONDITIONS) ?? [];
        if ([] === $criteria && [] === $conditions) {
            return [];
        }

        return [
            new ScopedHandler(
                new Mapper(static fn (mixed $request): mixed => self::render($criteria, $conditions)),
                SignalKind::MONGO_QUERY,
                self::PRIORITY,
            ),
        ];
    }

    /**
     * @param list<mixed> $criteria
     * @param list<mixed> $conditions
     *
     * @return array<string, mixed>
     */
    private static function render(array $criteria, array $conditions): array
    {
        $rendered = BsonCriteriaRenderer::render($criteria, self::stringConditions($conditions));

        return $rendered ?? [];
    }

    /**
     * @param list<mixed> $conditions
     *
     * @return list<string>
     */
    private static function stringConditions(array $conditions): array
    {
        $strings = [];
        foreach ($conditions as $condition) {
            if (!is_string($condition)) {
                throw new AccessDeniedException(self::ERROR_NON_STRING_CONDITION);
            }
            $strings[] = $condition;
        }

        return $strings;
    }
}
