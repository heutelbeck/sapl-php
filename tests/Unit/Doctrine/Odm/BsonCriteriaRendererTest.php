<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Doctrine\Odm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sapl\Doctrine\Odm\BsonCriteriaRenderer;
use Sapl\Pep\AccessDeniedException;

final class BsonCriteriaRendererTest extends TestCase
{
    public function testReturnsNullWhenNothingToRender(): void
    {
        self::assertNull(BsonCriteriaRenderer::render([], []));
    }

    /**
     * @param list<mixed>          $criteria
     * @param array<string, mixed> $expected
     */
    #[DataProvider('leafCases')]
    public function testRendersLeafCriteria(array $criteria, array $expected): void
    {
        self::assertSame($expected, BsonCriteriaRenderer::render($criteria, []));
    }

    /**
     * @return iterable<string, array{list<mixed>, array<string, mixed>}>
     */
    public static function leafCases(): iterable
    {
        yield 'equals' => [[['column' => 'tenantId', 'op' => '=', 'value' => 7]], ['tenantId' => 7]];
        yield 'not equals' => [[['column' => 'state', 'op' => '!=', 'value' => 'x']], ['state' => ['$ne' => 'x']]];
        yield 'greater than' => [[['column' => 'age', 'op' => '>', 'value' => 18]], ['age' => ['$gt' => 18]]];
        yield 'greater or equal' => [[['column' => 'age', 'op' => '>=', 'value' => 18]], ['age' => ['$gte' => 18]]];
        yield 'less than' => [[['column' => 'age', 'op' => '<', 'value' => 18]], ['age' => ['$lt' => 18]]];
        yield 'less or equal' => [[['column' => 'age', 'op' => '<=', 'value' => 18]], ['age' => ['$lte' => 18]]];
        yield 'in list' => [[['column' => 'id', 'op' => 'in', 'value' => [1, 2, 3]]], ['id' => ['$in' => [1, 2, 3]]]];
        yield 'is null' => [[['column' => 'deletedAt', 'op' => 'isNull']], ['deletedAt' => null]];
        yield 'is not null' => [[['column' => 'deletedAt', 'op' => 'isNotNull']], ['deletedAt' => ['$ne' => null]]];
        yield 'boolean' => [[['column' => 'active', 'op' => '=', 'value' => true]], ['active' => true]];
    }

    public function testRendersNestedOrGroup(): void
    {
        $criteria = [[
            'or' => [
                ['column' => 'ownerId', 'op' => '=', 'value' => 'alice'],
                ['column' => 'public', 'op' => '=', 'value' => true],
            ],
        ]];

        self::assertSame(['$or' => [['ownerId' => 'alice'], ['public' => true]]], BsonCriteriaRenderer::render($criteria, []));
    }

    public function testRendersNestedAndGroup(): void
    {
        $criteria = [[
            'and' => [
                ['column' => 'a', 'op' => '=', 'value' => 1],
                ['column' => 'b', 'op' => '=', 'value' => 2],
            ],
        ]];

        self::assertSame(['$and' => [['a' => 1], ['b' => 2]]], BsonCriteriaRenderer::render($criteria, []));
    }

    public function testAndCombinesMultipleCriteriaUnderTopLevelAnd(): void
    {
        $criteria = [
            ['column' => 'tenantId', 'op' => '=', 'value' => 7],
            ['column' => 'deletedAt', 'op' => 'isNull'],
        ];

        self::assertSame(['$and' => [['tenantId' => 7], ['deletedAt' => null]]], BsonCriteriaRenderer::render($criteria, []));
    }

    public function testMergesStrictJsonConditions(): void
    {
        $criteria = [['column' => 'tenantId', 'op' => '=', 'value' => 7]];
        $conditions = ['{"age": {"$gte": 18}}'];

        self::assertSame(
            ['$and' => [['tenantId' => 7], ['age' => ['$gte' => 18]]]],
            BsonCriteriaRenderer::render($criteria, $conditions),
        );
    }

    public function testSingleConditionIsReturnedUnwrapped(): void
    {
        self::assertSame(['age' => ['$gte' => 18]], BsonCriteriaRenderer::render([], ['{"age": {"$gte": 18}}']));
    }

    /**
     * @param array<string, mixed> $leaf
     */
    #[DataProvider('skippedLeafCases')]
    public function testUnbuildableLeafIsSkipped(array $leaf): void
    {
        // A single unbuildable leaf contributes nothing, so the result is null.
        self::assertNull(BsonCriteriaRenderer::render([$leaf], []));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function skippedLeafCases(): iterable
    {
        yield 'missing column' => [['op' => '=', 'value' => 1]];
        yield 'missing op' => [['column' => 'c', 'value' => 1]];
        yield 'unsupported op' => [['column' => 'c', 'op' => 'like', 'value' => 'a%']];
        yield 'missing value' => [['column' => 'c', 'op' => '=']];
        yield 'in with non-array value' => [['column' => 'c', 'op' => 'in', 'value' => 5]];
    }

    public function testFailsClosedOnNonStrictJsonCondition(): void
    {
        $this->expectException(AccessDeniedException::class);

        BsonCriteriaRenderer::render([], ["{'tenantId': 7}"]);
    }
}
