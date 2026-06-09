<?php

declare(strict_types=1);

namespace Sapl\Doctrine\Odm;

use JsonException;
use Sapl\Pep\AccessDeniedException;

/**
 * Renders the typed criteria tree and string conditions of a mongo:queryRewriting
 * obligation into a single BSON criteria document.
 *
 * The renderer ports the Spring MongoDbQueryRewritingProvider: typed leaves map
 * to BSON field predicates with ops = != > >= < <= in isNull isNotNull (there is
 * no like or notLike for Mongo; use a $regex condition for that), and and/or
 * group nested criteria under $and/$or. The string conditions are an escape hatch
 * for Mongo-specific operators ($regex, $exists, $geoWithin) and must be strict
 * JSON, parsed with json_decode and JSON_THROW_ON_ERROR so the same condition
 * string parses identically on every SAPL Mongo PEP. Shell syntax (single quotes,
 * unquoted keys) is rejected.
 *
 * Typed criteria and conditions are AND-combined under a top-level $and, so the
 * obligation can only narrow the result set, never widen it. Doctrine ODM then
 * AND-merges the returned document across the documents the query touches.
 *
 * An unbuildable typed leaf (missing column or op, unsupported op, missing value,
 * in with a non-array value) is skipped, matching the reference's Optional.empty
 * behaviour. A non-strict-JSON condition fails closed by raising
 * AccessDeniedException.
 */
final class BsonCriteriaRenderer
{
    private const string ERROR_NON_JSON_CONDITION = 'A mongo:queryRewriting condition is not strict JSON: %s';

    private const string FIELD_AND = 'and';
    private const string FIELD_COLUMN = 'column';
    private const string FIELD_OP = 'op';
    private const string FIELD_OR = 'or';
    private const string FIELD_VALUE = 'value';

    private function __construct()
    {
    }

    /**
     * Render the AND-combined BSON criteria document, or null when no criteria or
     * conditions contribute a fragment.
     *
     * @param list<mixed>  $criteria   the typed criteria tree
     * @param list<string> $conditions strict-JSON condition strings
     *
     * @return array<string, mixed>|null
     */
    public static function render(array $criteria, array $conditions): ?array
    {
        $fragments = [];
        foreach ($criteria as $node) {
            $fragment = self::buildNode($node);
            if (null !== $fragment) {
                $fragments[] = $fragment;
            }
        }
        foreach ($conditions as $condition) {
            $parsed = self::parseStrictCondition($condition);
            if ([] !== $parsed) {
                $fragments[] = $parsed;
            }
        }
        if ([] === $fragments) {
            return null;
        }
        if (1 === count($fragments)) {
            return $fragments[0];
        }

        return ['$and' => $fragments];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function buildNode(mixed $node): ?array
    {
        if (!is_array($node)) {
            return null;
        }
        if (isset($node[self::FIELD_OR]) && is_array($node[self::FIELD_OR])) {
            return self::buildGroup(array_values($node[self::FIELD_OR]), '$or');
        }
        if (isset($node[self::FIELD_AND]) && is_array($node[self::FIELD_AND])) {
            return self::buildGroup(array_values($node[self::FIELD_AND]), '$and');
        }

        return self::buildLeaf($node);
    }

    /**
     * @param list<mixed> $children
     *
     * @return array<string, mixed>|null
     */
    private static function buildGroup(array $children, string $operator): ?array
    {
        $parts = [];
        foreach ($children as $child) {
            $fragment = self::buildNode($child);
            if (null !== $fragment) {
                $parts[] = $fragment;
            }
        }
        if ([] === $parts) {
            return null;
        }

        return [$operator => $parts];
    }

    /**
     * @param array<mixed> $leaf
     *
     * @return array<string, mixed>|null
     */
    private static function buildLeaf(array $leaf): ?array
    {
        $column = $leaf[self::FIELD_COLUMN] ?? null;
        $op = $leaf[self::FIELD_OP] ?? null;
        if (!is_string($column) || !is_string($op)) {
            return null;
        }
        if ('isNull' === $op) {
            return [$column => null];
        }
        if ('isNotNull' === $op) {
            return [$column => ['$ne' => null]];
        }
        if (!array_key_exists(self::FIELD_VALUE, $leaf)) {
            return null;
        }

        return self::applyBinaryOp($column, $op, $leaf[self::FIELD_VALUE]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function applyBinaryOp(string $column, string $op, mixed $value): ?array
    {
        return match ($op) {
            '=' => [$column => $value],
            '!=' => [$column => ['$ne' => $value]],
            '>' => [$column => ['$gt' => $value]],
            '>=' => [$column => ['$gte' => $value]],
            '<' => [$column => ['$lt' => $value]],
            '<=' => [$column => ['$lte' => $value]],
            'in' => is_array($value) ? [$column => ['$in' => array_values($value)]] : null,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseStrictCondition(string $condition): array
    {
        try {
            $parsed = json_decode($condition, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new AccessDeniedException(sprintf(self::ERROR_NON_JSON_CONDITION, $condition), 0, $exception);
        }
        if (!is_array($parsed)) {
            return [];
        }
        $document = [];
        foreach ($parsed as $key => $value) {
            $document[(string) $key] = $value;
        }

        return $document;
    }
}
