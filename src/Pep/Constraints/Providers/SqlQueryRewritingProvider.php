<?php

declare(strict_types=1);

namespace Sapl\Pep\Constraints\Providers;

use Sapl\Doctrine\Orm\SqlCriteriaRenderer;
use Sapl\Doctrine\Orm\SqlManipulationRequest;
use Sapl\Pep\AccessDeniedException;
use Sapl\Pep\Constraints\ConstraintGuards;
use Sapl\Pep\Constraints\ConstraintHandlerProvider;
use Sapl\Pep\Constraints\Mapper;
use Sapl\Pep\Constraints\ScopedHandler;
use Sapl\Pep\Constraints\SignalKind;

/**
 * Translates a sql:queryRewriting (or alias relational:queryRewriting)
 * obligation into a mapper on the SQL_QUERY signal that renders a narrowing SQL
 * predicate for the alias the Doctrine filter is scoping.
 *
 * The obligation row-narrows via a typed `criteria` tree and a string
 * `conditions` array, AND-combined and scoped to the filter's table alias. The
 * shapes match the relational and Mongo providers for cross-backend symmetry.
 *
 * A `columns` projection narrows the SELECT list. A Doctrine ORM query hydrates
 * entities, and a WHERE-only filter cannot change an entity-typed SELECT's
 * projection without changing its return shape, so a present `columns` entry
 * fails closed, matching the relational provider's COLUMNS_AGAINST_ENTITY_SELECT
 * behaviour.
 */
final class SqlQueryRewritingProvider implements ConstraintHandlerProvider
{
    private const string CONSTRAINT_TYPE_SQL = 'sql:queryRewriting';
    private const string CONSTRAINT_TYPE_RELATIONAL = 'relational:queryRewriting';
    private const int PRIORITY = 30;

    private const string FIELD_COLUMNS = 'columns';
    private const string FIELD_CONDITIONS = 'conditions';
    private const string FIELD_CRITERIA = 'criteria';

    private const string ERROR_COLUMNS_AGAINST_ENTITY_SELECT = 'Cannot narrow the projection of an entity-typed SELECT without changing its return shape.';
    private const string ERROR_NON_STRING_CONDITION = 'A condition in the obligation is not a string.';

    public function getConstraintHandlers(mixed $constraint, array $supportedSignals): array
    {
        if (!$this->isResponsible($constraint)) {
            return [];
        }
        if (!ConstraintGuards::supports($supportedSignals, SignalKind::SQL_QUERY)) {
            return [];
        }

        $criteria = ConstraintGuards::listField($constraint, self::FIELD_CRITERIA) ?? [];
        $conditions = ConstraintGuards::listField($constraint, self::FIELD_CONDITIONS) ?? [];
        $columns = ConstraintGuards::listField($constraint, self::FIELD_COLUMNS) ?? [];
        if ([] === $criteria && [] === $conditions && [] === $columns) {
            return [];
        }

        return [
            new ScopedHandler(
                new Mapper(static fn (mixed $request): mixed => self::render($criteria, $conditions, $columns, $request)),
                SignalKind::SQL_QUERY,
                self::PRIORITY,
            ),
        ];
    }

    /**
     * @param list<mixed> $criteria
     * @param list<mixed> $conditions
     * @param list<mixed> $columns
     */
    private static function render(array $criteria, array $conditions, array $columns, mixed $request): string
    {
        if ([] !== $columns) {
            throw new AccessDeniedException(self::ERROR_COLUMNS_AGAINST_ENTITY_SELECT);
        }
        $alias = $request instanceof SqlManipulationRequest ? $request->alias : '';
        $rendered = SqlCriteriaRenderer::render($criteria, self::stringConditions($conditions), $alias);

        return $rendered ?? '';
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

    private function isResponsible(mixed $constraint): bool
    {
        return ConstraintGuards::isOfType($constraint, self::CONSTRAINT_TYPE_SQL)
            || ConstraintGuards::isOfType($constraint, self::CONSTRAINT_TYPE_RELATIONAL);
    }
}
