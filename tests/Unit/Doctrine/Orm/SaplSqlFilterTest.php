<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Doctrine\Orm;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sapl\Api\AuthorizationDecision;
use Sapl\Api\Decision;
use Sapl\Doctrine\Orm\SaplSqlFilter;
use Sapl\Doctrine\Orm\SqlManipulationRequest;
use Sapl\Pep\AccessDeniedException;
use Sapl\Pep\Constraints\ActivePlan;
use Sapl\Pep\Constraints\EnforcementPlan;
use Sapl\Pep\Constraints\EnforcementPlanner;
use Sapl\Pep\Constraints\Mapper;
use Sapl\Pep\Constraints\Runner;
use Sapl\Pep\Constraints\ScopedHandler;
use Sapl\Pep\Constraints\SignalKind;
use Sapl\Tests\Fake\FakeConstraintHandlerProvider;

final class SaplSqlFilterTest extends TestCase
{
    /** @var list<SignalKind> */
    private const array PRE_WITH_SQL = [
        SignalKind::DECISION,
        SignalKind::INPUT,
        SignalKind::OUTPUT,
        SignalKind::ERROR,
        SignalKind::SQL_QUERY,
    ];

    protected function tearDown(): void
    {
        ActivePlan::reset();
    }

    public function testContributesNothingWhenNoPlanIsActive(): void
    {
        self::assertSame('', $this->filter()->addFilterConstraint($this->metadata(), 't0'));
    }

    public function testContributesNothingWhenPlanHasNoSqlObligation(): void
    {
        ActivePlan::set(EnforcementPlan::empty());

        self::assertSame('', $this->filter()->addFilterConstraint($this->metadata(), 't0'));
    }

    public function testDischargesSqlObligationAndReturnsRenderedPredicate(): void
    {
        $captured = null;
        $provider = new FakeConstraintHandlerProvider('sql:queryRewriting', [
            new ScopedHandler(new Mapper(static function (mixed $request) use (&$captured): mixed {
                self::assertInstanceOf(SqlManipulationRequest::class, $request);
                $captured = $request;

                return $request->alias.'.tenant_id = 7';
            }), SignalKind::SQL_QUERY, 30),
        ]);
        ActivePlan::set($this->plan([['type' => 'sql:queryRewriting']], $provider));

        $predicate = $this->filter()->addFilterConstraint($this->metadata(), 't0');

        self::assertInstanceOf(SqlManipulationRequest::class, $captured);
        self::assertSame('t0', $captured->alias);
        self::assertSame('t0.tenant_id = 7', $predicate);
    }

    public function testFailsClosedWhenObligationHandlerFails(): void
    {
        $provider = new FakeConstraintHandlerProvider('sql:queryRewriting', [
            new ScopedHandler(new Runner(static fn () => throw new RuntimeException('boom')), SignalKind::SQL_QUERY, 30),
        ]);
        ActivePlan::set($this->plan([['type' => 'sql:queryRewriting']], $provider));
        $filter = $this->filter();
        $metadata = $this->metadata();

        $this->expectException(AccessDeniedException::class);

        $filter->addFilterConstraint($metadata, 't0');
    }

    private function filter(): SaplSqlFilter
    {
        $em = $this->createMock(EntityManagerInterface::class);

        return new SaplSqlFilter($em);
    }

    /**
     * @return ClassMetadata<object>
     */
    private function metadata(): ClassMetadata
    {
        return $this->createMock(ClassMetadata::class);
    }

    /**
     * @param list<mixed> $obligations
     */
    private function plan(array $obligations, FakeConstraintHandlerProvider $provider): EnforcementPlan
    {
        $decision = new AuthorizationDecision(Decision::PERMIT, $obligations);

        return (new EnforcementPlanner([$provider]))->plan($decision, self::PRE_WITH_SQL);
    }
}
