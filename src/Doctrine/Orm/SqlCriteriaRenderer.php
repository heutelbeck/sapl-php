<?php

declare(strict_types=1);

namespace Sapl\Doctrine\Orm;

use Sapl\Pep\AccessDeniedException;

/**
 * Renders the typed criteria tree and string conditions of a sql:queryRewriting
 * obligation into a single SQL predicate fragment, scoped to a table alias.
 *
 * The renderer ports the Spring SqlQueryRewritingProvider leaf, binary, value,
 * and array rendering. Each criterion column is prefixed with the alias the
 * Doctrine filter is scoping, so the predicate binds to the right table across
 * the root entity, joins, and subqueries. Typed criteria and string conditions
 * are AND-combined and each fragment is wrapped in parentheses, so an OR inside a
 * criterion or condition cannot widen the result set.
 *
 * Values are inlined as escaped literals (single quotes doubled), not bound
 * parameters: the Doctrine filter is invoked many times per query and inlining
 * keeps the rendering a pure function of the obligation and the alias.
 *
 * Supported operators: = != > >= < <= in like notLike isNull isNotNull. Every
 * failure mode (missing column or op, unsupported op, value kind incompatible
 * with the op, missing value) fails closed by raising AccessDeniedException.
 */
final class SqlCriteriaRenderer
{
    private const string ERROR_MISSING_COLUMN = "Criterion leaf has no 'column' string field.";
    private const string ERROR_MISSING_OP = "Criterion leaf has no 'op' string field.";
    private const string ERROR_UNSUPPORTED_OPERATOR = 'Unsupported operator in typed criterion: %s';
    private const string ERROR_VALUE_KIND_FOR_OPERATOR = 'Value kind %s incompatible with operator %s';
    private const string ERROR_VALUE_REQUIRED = 'Value required for operator %s';

    private const string FIELD_AND = 'and';
    private const string FIELD_COLUMN = 'column';
    private const string FIELD_OP = 'op';
    private const string FIELD_OR = 'or';
    private const string FIELD_VALUE = 'value';

    private function __construct()
    {
    }

    /**
     * Render the AND-combined predicate, or null when no criteria or conditions
     * contribute a fragment.
     *
     * @param list<mixed>  $criteria   the typed criteria tree
     * @param list<string> $conditions raw SQL condition strings
     */
    public static function render(array $criteria, array $conditions, string $alias): ?string
    {
        $fragments = [];
        foreach ($criteria as $node) {
            $rendered = self::renderCriterionNode($node, $alias);
            if (null !== $rendered) {
                $fragments[] = $rendered;
            }
        }
        foreach ($conditions as $condition) {
            $fragments[] = $condition;
        }
        if ([] === $fragments) {
            return null;
        }

        return implode(' AND ', array_map(static fn (string $fragment): string => '('.$fragment.')', $fragments));
    }

    private static function renderCriterionNode(mixed $node, string $alias): ?string
    {
        if (!is_array($node)) {
            return null;
        }
        if (isset($node[self::FIELD_OR]) && is_array($node[self::FIELD_OR])) {
            return self::renderGroup(array_values($node[self::FIELD_OR]), ' OR ', $alias);
        }
        if (isset($node[self::FIELD_AND]) && is_array($node[self::FIELD_AND])) {
            return self::renderGroup(array_values($node[self::FIELD_AND]), ' AND ', $alias);
        }

        return self::renderLeaf($node, $alias);
    }

    /**
     * @param list<mixed> $children
     */
    private static function renderGroup(array $children, string $joiner, string $alias): ?string
    {
        $parts = [];
        foreach ($children as $child) {
            $rendered = self::renderCriterionNode($child, $alias);
            if (null !== $rendered) {
                $parts[] = $rendered;
            }
        }
        if ([] === $parts) {
            return null;
        }

        return '('.implode($joiner, $parts).')';
    }

    /**
     * @param array<mixed> $node
     */
    private static function renderLeaf(array $node, string $alias): string
    {
        $column = $node[self::FIELD_COLUMN] ?? null;
        if (!is_string($column)) {
            throw new AccessDeniedException(self::ERROR_MISSING_COLUMN);
        }
        $op = $node[self::FIELD_OP] ?? null;
        if (!is_string($op)) {
            throw new AccessDeniedException(self::ERROR_MISSING_OP);
        }
        $qualified = $alias.'.'.$column;
        if ('isNull' === $op) {
            return $qualified.' IS NULL';
        }
        if ('isNotNull' === $op) {
            return $qualified.' IS NOT NULL';
        }
        if (!array_key_exists(self::FIELD_VALUE, $node) || null === $node[self::FIELD_VALUE]) {
            throw new AccessDeniedException(sprintf(self::ERROR_VALUE_REQUIRED, $op));
        }

        return self::renderBinary($qualified, $op, $node[self::FIELD_VALUE]);
    }

    private static function renderBinary(string $column, string $op, mixed $value): string
    {
        return match ($op) {
            '=' => $column.' = '.self::renderValue($value, $op),
            '!=' => $column.' <> '.self::renderValue($value, $op),
            '>' => $column.' > '.self::renderValue($value, $op),
            '>=' => $column.' >= '.self::renderValue($value, $op),
            '<' => $column.' < '.self::renderValue($value, $op),
            '<=' => $column.' <= '.self::renderValue($value, $op),
            'in' => $column.' IN '.self::renderArray($value, $op),
            'like' => $column.' LIKE '.self::renderText($value, $op),
            'notLike' => $column.' NOT LIKE '.self::renderText($value, $op),
            default => throw new AccessDeniedException(sprintf(self::ERROR_UNSUPPORTED_OPERATOR, $op)),
        };
    }

    private static function renderValue(mixed $value, string $op): string
    {
        return match (true) {
            is_string($value) => "'".str_replace("'", "''", $value)."'",
            is_int($value) => (string) $value,
            is_float($value) => self::renderFloat($value),
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            null === $value => 'NULL',
            default => throw new AccessDeniedException(sprintf(self::ERROR_VALUE_KIND_FOR_OPERATOR, get_debug_type($value), $op)),
        };
    }

    private static function renderArray(mixed $value, string $op): string
    {
        if (!is_array($value)) {
            throw new AccessDeniedException(sprintf(self::ERROR_VALUE_KIND_FOR_OPERATOR, get_debug_type($value), $op));
        }
        $parts = array_map(static fn (mixed $element): string => self::renderValue($element, $op), array_values($value));

        return '('.implode(', ', $parts).')';
    }

    private static function renderText(mixed $value, string $op): string
    {
        if (!is_string($value)) {
            throw new AccessDeniedException(sprintf(self::ERROR_VALUE_KIND_FOR_OPERATOR, get_debug_type($value), $op));
        }

        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * Render a float as a plain decimal string, matching the reference's
     * BigDecimal toPlainString (no scientific notation, no trailing format
     * artefacts).
     */
    private static function renderFloat(float $value): string
    {
        $rendered = rtrim(rtrim(sprintf('%.14F', $value), '0'), '.');

        return '' === $rendered ? '0' : $rendered;
    }
}
