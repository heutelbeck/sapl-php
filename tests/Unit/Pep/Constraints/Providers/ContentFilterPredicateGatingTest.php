<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Pep\Constraints\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sapl\Pep\AccessDeniedException;
use Sapl\Pep\Constraints\Mapper;
use Sapl\Pep\Constraints\Providers\ContentFilteringProvider;
use Sapl\Pep\Constraints\SignalKind;

/**
 * A `filterJsonContent` obligation may carry a `conditions` predicate that gates
 * its redaction `actions`: matching records are transformed, non-matching records
 * pass through unchanged, and a condition whose path is absent denies the access.
 *
 * Mirrors the Spring reference (ContentFilter#predicateFromConditions and
 * #mapElement). Traceability: CF-CONDITIONS-MISSING.
 */
final class ContentFilterPredicateGatingTest extends TestCase
{
    public function testRedactsOnlyRecordsMatchingTheCondition(): void
    {
        $constraint = [
            'type' => 'filterJsonContent',
            'conditions' => [['path' => '$.role', 'type' => '==', 'value' => 'admin']],
            'actions' => [['type' => 'delete', 'path' => '$.salary']],
        ];

        $result = $this->filter($constraint, [
            ['role' => 'admin', 'salary' => 100],
            ['role' => 'user', 'salary' => 50],
        ]);

        self::assertSame([
            ['role' => 'admin'],
            ['role' => 'user', 'salary' => 50],
        ], $result);
    }

    #[DataProvider('comparisonOperatorCases')]
    public function testOperatorGatesTransformationPerElement(
        string $operator,
        string $field,
        int|string $conditionValue,
        int|string $matchingValue,
        int|string $nonMatchingValue,
    ): void {
        $constraint = [
            'type' => 'filterJsonContent',
            'conditions' => [['path' => '$.'.$field, 'type' => $operator, 'value' => $conditionValue]],
            'actions' => [['type' => 'delete', 'path' => '$.salary']],
        ];

        $result = $this->filter($constraint, [
            [$field => $matchingValue, 'salary' => 100],
            [$field => $nonMatchingValue, 'salary' => 100],
        ]);

        self::assertSame([
            [$field => $matchingValue],
            [$field => $nonMatchingValue, 'salary' => 100],
        ], $result);
    }

    /**
     * @return iterable<string, array{string, string, int|string, int|string, int|string}>
     */
    public static function comparisonOperatorCases(): iterable
    {
        yield 'equality on text' => ['==', 'role', 'admin', 'admin', 'user'];
        yield 'inequality on text' => ['!=', 'role', 'admin', 'user', 'admin'];
        yield 'greater or equal' => ['>=', 'age', 18, 18, 17];
        yield 'less or equal' => ['<=', 'age', 18, 18, 19];
        yield 'greater than' => ['>', 'age', 18, 19, 18];
        yield 'less than' => ['<', 'age', 18, 17, 18];
        yield 'regex match' => ['=~', 'role', '^adm.*$', 'admin', 'user'];
    }

    public function testConjunctiveConditionsRequireEveryPredicateToMatch(): void
    {
        $constraint = [
            'type' => 'filterJsonContent',
            'conditions' => [
                ['path' => '$.role', 'type' => '==', 'value' => 'admin'],
                ['path' => '$.dept', 'type' => '==', 'value' => 'hr'],
            ],
            'actions' => [['type' => 'delete', 'path' => '$.salary']],
        ];

        $result = $this->filter($constraint, [
            ['role' => 'admin', 'dept' => 'hr', 'salary' => 100],
            ['role' => 'admin', 'dept' => 'eng', 'salary' => 100],
        ]);

        self::assertSame([
            ['role' => 'admin', 'dept' => 'hr'],
            ['role' => 'admin', 'dept' => 'eng', 'salary' => 100],
        ], $result);
    }

    public function testNumericEqualityComparesExactValueBeyondDoublePrecision(): void
    {
        $constraint = [
            'type' => 'filterJsonContent',
            'conditions' => [['path' => '$.id', 'type' => '==', 'value' => 9007199254740993]],
            'actions' => [['type' => 'delete', 'path' => '$.salary']],
        ];

        $result = $this->filter($constraint, [
            ['id' => 9007199254740993, 'salary' => 100],
            ['id' => 9007199254740992, 'salary' => 100],
        ]);

        self::assertSame([
            ['id' => 9007199254740993],
            ['id' => 9007199254740992, 'salary' => 100],
        ], $result);
    }

    public function testDeniesWhenConditionPathIsAbsentFromThePayload(): void
    {
        $constraint = [
            'type' => 'filterJsonContent',
            'conditions' => [['path' => '$.role', 'type' => '==', 'value' => 'admin']],
            'actions' => [['type' => 'delete', 'path' => '$.salary']],
        ];
        $mapper = $this->mapperOf($constraint);

        $this->expectException(AccessDeniedException::class);

        $mapper(['name' => 'bob', 'salary' => 100]);
    }

    /**
     * @param array<string, mixed> $constraint
     */
    private function filter(array $constraint, mixed $payload): mixed
    {
        return ($this->mapperOf($constraint))($payload);
    }

    /**
     * @param array<string, mixed> $constraint
     *
     * @return callable(mixed): mixed
     */
    private function mapperOf(array $constraint): callable
    {
        $handlers = (new ContentFilteringProvider())->getConstraintHandlers($constraint, [SignalKind::OUTPUT]);
        self::assertCount(1, $handlers);
        $mapper = $handlers[0]->handler;
        self::assertInstanceOf(Mapper::class, $mapper);

        return $mapper->apply;
    }
}
