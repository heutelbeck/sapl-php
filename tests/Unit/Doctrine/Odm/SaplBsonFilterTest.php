<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Doctrine\Odm;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sapl\Api\AuthorizationDecision;
use Sapl\Api\Decision;
use Sapl\Doctrine\Odm\MongoManipulationRequest;
use Sapl\Doctrine\Odm\SaplBsonFilter;
use Sapl\Pep\AccessDeniedException;
use Sapl\Pep\Constraints\ActivePlan;
use Sapl\Pep\Constraints\EnforcementPlan;
use Sapl\Pep\Constraints\EnforcementPlanner;
use Sapl\Pep\Constraints\Mapper;
use Sapl\Pep\Constraints\Runner;
use Sapl\Pep\Constraints\ScopedHandler;
use Sapl\Pep\Constraints\SignalKind;
use Sapl\Tests\Fake\FakeConstraintHandlerProvider;

final class SaplBsonFilterTest extends TestCase
{
    /** @var list<SignalKind> */
    private const array PRE_WITH_MONGO = [
        SignalKind::DECISION,
        SignalKind::INPUT,
        SignalKind::OUTPUT,
        SignalKind::ERROR,
        SignalKind::MONGO_QUERY,
    ];

    protected function tearDown(): void
    {
        ActivePlan::reset();
    }

    public function testContributesEmptyCriteriaWhenNoPlanIsActive(): void
    {
        self::assertSame([], $this->filter()->addFilterCriteria($this->metadata()));
    }

    public function testContributesEmptyCriteriaWhenPlanHasNoMongoObligation(): void
    {
        ActivePlan::set(EnforcementPlan::empty());

        self::assertSame([], $this->filter()->addFilterCriteria($this->metadata()));
    }

    public function testDischargesMongoObligationAndReturnsRenderedCriteria(): void
    {
        $captured = null;
        $provider = new FakeConstraintHandlerProvider('mongo:queryRewriting', [
            new ScopedHandler(new Mapper(static function (mixed $request) use (&$captured): mixed {
                self::assertInstanceOf(MongoManipulationRequest::class, $request);
                $captured = $request;

                return ['tenantId' => 7];
            }), SignalKind::MONGO_QUERY, 30),
        ]);
        ActivePlan::set($this->plan([['type' => 'mongo:queryRewriting']], $provider));

        $criteria = $this->filter()->addFilterCriteria($this->metadata());

        self::assertInstanceOf(MongoManipulationRequest::class, $captured);
        self::assertSame(['tenantId' => 7], $criteria);
    }

    public function testFailsClosedWhenObligationHandlerFails(): void
    {
        $provider = new FakeConstraintHandlerProvider('mongo:queryRewriting', [
            new ScopedHandler(new Runner(static fn () => throw new RuntimeException('boom')), SignalKind::MONGO_QUERY, 30),
        ]);
        ActivePlan::set($this->plan([['type' => 'mongo:queryRewriting']], $provider));
        $filter = $this->filter();
        $metadata = $this->metadata();

        $this->expectException(AccessDeniedException::class);

        $filter->addFilterCriteria($metadata);
    }

    private function filter(): SaplBsonFilter
    {
        $dm = $this->createMock(DocumentManager::class);

        return new SaplBsonFilter($dm);
    }

    /**
     * @return ClassMetadata<object>
     */
    private function metadata(): ClassMetadata
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getName')->willReturn(self::class);

        return $metadata;
    }

    /**
     * @param list<mixed> $obligations
     */
    private function plan(array $obligations, FakeConstraintHandlerProvider $provider): EnforcementPlan
    {
        $decision = new AuthorizationDecision(Decision::PERMIT, $obligations);

        return (new EnforcementPlanner([$provider]))->plan($decision, self::PRE_WITH_MONGO);
    }
}
