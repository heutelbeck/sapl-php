<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Pep\Constraints\Providers;

use PHPUnit\Framework\TestCase;
use Sapl\Doctrine\Odm\MongoManipulationRequest;
use Sapl\Pep\AccessDeniedException;
use Sapl\Pep\Constraints\Mapper;
use Sapl\Pep\Constraints\Providers\MongoQueryRewritingProvider;
use Sapl\Pep\Constraints\SignalKind;

final class MongoQueryRewritingProviderTest extends TestCase
{
    /** @var list<SignalKind> */
    private const array SUPPORTED = [SignalKind::DECISION, SignalKind::MONGO_QUERY];

    public function testIgnoresUnrelatedConstraint(): void
    {
        self::assertSame([], $this->provider()->getConstraintHandlers(['type' => 'sql:queryRewriting'], self::SUPPORTED));
    }

    public function testYieldsNoHandlerWhenMongoSignalUnsupported(): void
    {
        $constraint = ['type' => 'mongo:queryRewriting', 'criteria' => [['column' => 'tenantId', 'op' => '=', 'value' => 7]]];

        self::assertSame([], $this->provider()->getConstraintHandlers($constraint, [SignalKind::DECISION]));
    }

    public function testYieldsNoHandlerWhenObligationHasNoNarrowing(): void
    {
        self::assertSame([], $this->provider()->getConstraintHandlers(['type' => 'mongo:queryRewriting'], self::SUPPORTED));
    }

    public function testClaimsMongoOnMongoQuerySignal(): void
    {
        $constraint = ['type' => 'mongo:queryRewriting', 'criteria' => [['column' => 'tenantId', 'op' => '=', 'value' => 7]]];
        $handlers = $this->provider()->getConstraintHandlers($constraint, self::SUPPORTED);

        self::assertCount(1, $handlers);
        self::assertSame(SignalKind::MONGO_QUERY, $handlers[0]->signal);
        self::assertSame(30, $handlers[0]->priority);
        self::assertInstanceOf(Mapper::class, $handlers[0]->handler);
    }

    public function testMapperRendersSingleCriterionDocument(): void
    {
        $constraint = ['type' => 'mongo:queryRewriting', 'criteria' => [['column' => 'tenantId', 'op' => '=', 'value' => 7]]];

        self::assertSame(['tenantId' => 7], $this->mapperOf($constraint)(new MongoManipulationRequest(self::class)));
    }

    public function testMapperAndCombinesCriterionAndCondition(): void
    {
        $constraint = [
            'type' => 'mongo:queryRewriting',
            'criteria' => [['column' => 'tenantId', 'op' => '=', 'value' => 7]],
            'conditions' => ['{"age": {"$gte": 18}}'],
        ];

        self::assertSame(
            ['$and' => [['tenantId' => 7], ['age' => ['$gte' => 18]]]],
            $this->mapperOf($constraint)(new MongoManipulationRequest(self::class)),
        );
    }

    public function testMapperFailsClosedWhenConditionIsNotStrictJson(): void
    {
        $constraint = ['type' => 'mongo:queryRewriting', 'conditions' => ["{'tenantId': 7}"]];
        $mapper = $this->mapperOf($constraint);

        $this->expectException(AccessDeniedException::class);

        $mapper(new MongoManipulationRequest(self::class));
    }

    public function testMapperFailsClosedWhenConditionIsNotString(): void
    {
        $constraint = ['type' => 'mongo:queryRewriting', 'conditions' => [['nested' => 'value']]];
        $mapper = $this->mapperOf($constraint);

        $this->expectException(AccessDeniedException::class);

        $mapper(new MongoManipulationRequest(self::class));
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

    private function provider(): MongoQueryRewritingProvider
    {
        return new MongoQueryRewritingProvider();
    }
}
