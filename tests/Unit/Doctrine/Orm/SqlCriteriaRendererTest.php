<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Doctrine\Orm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sapl\Doctrine\Orm\SqlCriteriaRenderer;
use Sapl\Pep\AccessDeniedException;

final class SqlCriteriaRendererTest extends TestCase
{
    public function testReturnsNullWhenNothingToRender(): void
    {
        self::assertNull(SqlCriteriaRenderer::render([], [], 't0'));
    }

    /**
     * @param list<mixed> $criteria
     */
    #[DataProvider('leafCases')]
    public function testRendersLeafCriteria(array $criteria, string $expected): void
    {
        self::assertSame($expected, SqlCriteriaRenderer::render($criteria, [], 't0'));
    }

    /**
     * @return iterable<string, array{list<mixed>, string}>
     */
    public static function leafCases(): iterable
    {
        yield 'equals number' => [[['column' => 'tenant_id', 'op' => '=', 'value' => 7]], '(t0.tenant_id = 7)'];
        yield 'not equals' => [[['column' => 'state', 'op' => '!=', 'value' => 'x']], "(t0.state <> 'x')"];
        yield 'greater than' => [[['column' => 'age', 'op' => '>', 'value' => 18]], '(t0.age > 18)'];
        yield 'greater or equal' => [[['column' => 'age', 'op' => '>=', 'value' => 18]], '(t0.age >= 18)'];
        yield 'less than' => [[['column' => 'age', 'op' => '<', 'value' => 18]], '(t0.age < 18)'];
        yield 'less or equal' => [[['column' => 'age', 'op' => '<=', 'value' => 18]], '(t0.age <= 18)'];
        yield 'in list' => [[['column' => 'id', 'op' => 'in', 'value' => [1, 2, 3]]], '(t0.id IN (1, 2, 3))'];
        yield 'like' => [[['column' => 'name', 'op' => 'like', 'value' => 'a%']], "(t0.name LIKE 'a%')"];
        yield 'not like' => [[['column' => 'name', 'op' => 'notLike', 'value' => 'a%']], "(t0.name NOT LIKE 'a%')"];
        yield 'is null' => [[['column' => 'deleted_at', 'op' => 'isNull']], '(t0.deleted_at IS NULL)'];
        yield 'is not null' => [[['column' => 'deleted_at', 'op' => 'isNotNull']], '(t0.deleted_at IS NOT NULL)'];
        yield 'boolean true' => [[['column' => 'active', 'op' => '=', 'value' => true]], '(t0.active = TRUE)'];
        yield 'boolean false' => [[['column' => 'active', 'op' => '=', 'value' => false]], '(t0.active = FALSE)'];
    }

    public function testEscapesSingleQuotesByDoubling(): void
    {
        $criteria = [['column' => 'owner', 'op' => '=', 'value' => "O'Brien"]];

        self::assertSame("(t0.owner = 'O''Brien')", SqlCriteriaRenderer::render($criteria, [], 't0'));
    }

    public function testRendersNestedOrGroup(): void
    {
        $criteria = [[
            'or' => [
                ['column' => 'owner_id', 'op' => '=', 'value' => 'alice'],
                ['column' => 'public', 'op' => '=', 'value' => true],
            ],
        ]];

        self::assertSame("((t0.owner_id = 'alice' OR t0.public = TRUE))", SqlCriteriaRenderer::render($criteria, [], 't0'));
    }

    public function testRendersNestedAndGroup(): void
    {
        $criteria = [[
            'and' => [
                ['column' => 'a', 'op' => '=', 'value' => 1],
                ['column' => 'b', 'op' => '=', 'value' => 2],
            ],
        ]];

        self::assertSame('((t0.a = 1 AND t0.b = 2))', SqlCriteriaRenderer::render($criteria, [], 't0'));
    }

    public function testAndCombinesCriteriaAndConditionsEachParenWrapped(): void
    {
        $criteria = [['column' => 'tenant_id', 'op' => '=', 'value' => 7]];
        $conditions = ["status IN ('active', 'pending')"];

        self::assertSame(
            "(t0.tenant_id = 7) AND (status IN ('active', 'pending'))",
            SqlCriteriaRenderer::render($criteria, $conditions, 't0'),
        );
    }

    public function testRespectsTheProvidedAlias(): void
    {
        $criteria = [['column' => 'tenant_id', 'op' => '=', 'value' => 7]];

        self::assertSame('(j1.tenant_id = 7)', SqlCriteriaRenderer::render($criteria, [], 'j1'));
    }

    /**
     * @param array<string, mixed> $leaf
     */
    #[DataProvider('failClosedCases')]
    public function testFailsClosed(array $leaf): void
    {
        $this->expectException(AccessDeniedException::class);

        SqlCriteriaRenderer::render([$leaf], [], 't0');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function failClosedCases(): iterable
    {
        yield 'missing column' => [['op' => '=', 'value' => 1]];
        yield 'missing op' => [['column' => 'c', 'value' => 1]];
        yield 'unsupported op' => [['column' => 'c', 'op' => 'between', 'value' => 1]];
        yield 'missing value' => [['column' => 'c', 'op' => '=']];
        yield 'null value' => [['column' => 'c', 'op' => '=', 'value' => null]];
        yield 'in with non-array value' => [['column' => 'c', 'op' => 'in', 'value' => 5]];
        yield 'like with non-string value' => [['column' => 'c', 'op' => 'like', 'value' => 5]];
        yield 'value kind incompatible' => [['column' => 'c', 'op' => '=', 'value' => ['nested' => 1]]];
    }
}
