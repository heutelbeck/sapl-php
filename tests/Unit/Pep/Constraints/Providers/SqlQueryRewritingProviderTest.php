<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Pep\Constraints\Providers;

use PHPUnit\Framework\TestCase;
use Sapl\Doctrine\Orm\SqlManipulationRequest;
use Sapl\Pep\AccessDeniedException;
use Sapl\Pep\Constraints\Mapper;
use Sapl\Pep\Constraints\Providers\SqlQueryRewritingProvider;
use Sapl\Pep\Constraints\SignalKind;

final class SqlQueryRewritingProviderTest extends TestCase
{
    /** @var list<SignalKind> */
    private const array SUPPORTED = [SignalKind::DECISION, SignalKind::SQL_QUERY];

    public function testIgnoresUnrelatedConstraint(): void
    {
        self::assertSame([], $this->provider()->getConstraintHandlers(['type' => 'other'], self::SUPPORTED));
    }

    public function testYieldsNoHandlerWhenSqlSignalUnsupported(): void
    {
        $constraint = ['type' => 'sql:queryRewriting', 'criteria' => [['column' => 'tenant_id', 'op' => '=', 'value' => 7]]];

        self::assertSame([], $this->provider()->getConstraintHandlers($constraint, [SignalKind::DECISION]));
    }

    public function testYieldsNoHandlerWhenObligationHasNoNarrowing(): void
    {
        self::assertSame([], $this->provider()->getConstraintHandlers(['type' => 'sql:queryRewriting'], self::SUPPORTED));
    }

    public function testClaimsSqlAndRelationalAliasOnSqlQuerySignal(): void
    {
        foreach (['sql:queryRewriting', 'relational:queryRewriting'] as $type) {
            $constraint = ['type' => $type, 'criteria' => [['column' => 'tenant_id', 'op' => '=', 'value' => 7]]];
            $handlers = $this->provider()->getConstraintHandlers($constraint, self::SUPPORTED);

            self::assertCount(1, $handlers);
            self::assertSame(SignalKind::SQL_QUERY, $handlers[0]->signal);
            self::assertSame(30, $handlers[0]->priority);
            self::assertInstanceOf(Mapper::class, $handlers[0]->handler);
        }
    }

    public function testMapperRendersAliasPrefixedPredicate(): void
    {
        $constraint = [
            'type' => 'sql:queryRewriting',
            'criteria' => [['column' => 'tenant_id', 'op' => '=', 'value' => 7]],
            'conditions' => ["status = 'active'"],
        ];

        self::assertSame("(t0.tenant_id = 7) AND (status = 'active')", $this->mapperOf($constraint)(new SqlManipulationRequest('t0')));
    }

    public function testMapperFailsClosedWhenColumnsPresent(): void
    {
        $constraint = [
            'type' => 'sql:queryRewriting',
            'criteria' => [['column' => 'tenant_id', 'op' => '=', 'value' => 7]],
            'columns' => ['id', 'name'],
        ];
        $mapper = $this->mapperOf($constraint);

        $this->expectException(AccessDeniedException::class);

        $mapper(new SqlManipulationRequest('t0'));
    }

    public function testMapperFailsClosedWhenConditionIsNotString(): void
    {
        $constraint = [
            'type' => 'sql:queryRewriting',
            'conditions' => [['nested' => 'value']],
        ];
        $mapper = $this->mapperOf($constraint);

        $this->expectException(AccessDeniedException::class);

        $mapper(new SqlManipulationRequest('t0'));
    }

    /**
     * @param array<string, mixed> $constraint
     *
     * @return callable(mixed): mixed
     */
    private function mapperOf(array $constraint): callable
    {
        $handlers = $this->provider()->getConstraintHandlers($constraint, self::SUPPORTED);
        $mapper = $handlers[0]->handler;
        self::assertInstanceOf(Mapper::class, $mapper);

        return $mapper->apply;
    }

    private function provider(): SqlQueryRewritingProvider
    {
        return new SqlQueryRewritingProvider();
    }
}
