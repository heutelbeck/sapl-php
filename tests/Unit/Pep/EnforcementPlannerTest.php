<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Pep;

use PHPUnit\Framework\TestCase;
use Sapl\Api\AuthorizationDecision;
use Sapl\Api\Decision;
use Sapl\Pep\Constraints\Consumer;
use Sapl\Pep\Constraints\EnforcementPlan;
use Sapl\Pep\Constraints\EnforcementPlanner;
use Sapl\Pep\Constraints\Mapper;
use Sapl\Pep\Constraints\Runner;
use Sapl\Pep\Constraints\ScopedHandler;
use Sapl\Pep\Constraints\SignalKind;
use Sapl\Pep\Present;
use Sapl\Tests\Fake\FakeConstraintHandlerProvider;

final class EnforcementPlannerTest extends TestCase
{
    /** @var list<SignalKind> */
    private const array SUPPORTED = [
        SignalKind::DECISION,
        SignalKind::INPUT,
        SignalKind::OUTPUT,
        SignalKind::ERROR,
        SignalKind::COMPLETE,
    ];

    public function testClaimedObligationSchedulesHandlerAtItsSignal(): void
    {
        $ran = false;
        $provider = new FakeConstraintHandlerProvider('log', [
            new ScopedHandler(new Runner(static function () use (&$ran): void {
                $ran = true;
            }), SignalKind::DECISION, 0),
        ]);
        $plan = $this->plan([['type' => 'log']], [], $provider);

        $result = $plan->execute(SignalKind::DECISION, new Present('d'), false);

        self::assertTrue($ran);
        self::assertFalse($result->failureState);
    }

    public function testUnresolvedObligationFailsClosed(): void
    {
        $plan = $this->plan([['type' => 'unknown']], []);

        self::assertTrue($plan->execute(SignalKind::DECISION, new Present('d'), false)->failureState);
    }

    public function testAmbiguousObligationFailsClosed(): void
    {
        $handler = new ScopedHandler(new Runner(static fn () => null), SignalKind::DECISION, 0);
        $plan = $this->plan(
            [['type' => 'foo']],
            [],
            new FakeConstraintHandlerProvider('foo', [$handler]),
            new FakeConstraintHandlerProvider('foo', [$handler]),
        );

        self::assertTrue($plan->execute(SignalKind::DECISION, new Present('d'), false)->failureState);
    }

    public function testInadmissibleHandlerFailsClosed(): void
    {
        // A mapper on a non-value-carrying signal (COMPLETE) is inadmissible.
        $provider = new FakeConstraintHandlerProvider('bad', [
            new ScopedHandler(new Mapper(static fn (mixed $v): mixed => $v), SignalKind::COMPLETE, 0),
        ]);
        $plan = $this->plan([['type' => 'bad']], [], $provider);

        self::assertTrue($plan->execute(SignalKind::DECISION, new Present('d'), false)->failureState);
    }

    public function testAdviceMapperIsInadmissibleButDoesNotDeny(): void
    {
        // Advice carrying a mapper is inadmissible; the substitute is advice-typed,
        // so it completes silently rather than denying.
        $provider = new FakeConstraintHandlerProvider('m', [
            new ScopedHandler(new Mapper(static fn (mixed $v): mixed => $v), SignalKind::OUTPUT, 0),
        ]);
        $plan = $this->plan([], [['type' => 'm']], $provider);

        self::assertFalse($plan->execute(SignalKind::DECISION, new Present('d'), false)->failureState);
    }

    public function testShimSignalMapperIsAdmittedWhenSignalIsSupported(): void
    {
        $provider = new FakeConstraintHandlerProvider('sql', [
            new ScopedHandler(new Mapper(static fn (mixed $v): mixed => $v), SignalKind::SQL_QUERY, 30),
        ]);
        $decision = new AuthorizationDecision(Decision::PERMIT, [['type' => 'sql']]);
        $supported = [...self::SUPPORTED, SignalKind::SQL_QUERY];

        $plan = (new EnforcementPlanner([$provider]))->plan($decision, $supported);

        // Admitted: scheduled on SQL_QUERY, the DECISION signal carries no failure.
        self::assertFalse($plan->execute(SignalKind::DECISION, new Present('d'), false)->failureState);
        self::assertCount(1, $plan->entriesFor(SignalKind::SQL_QUERY));
    }

    public function testShimSignalObligationFailsClosedWhenSignalUnsupported(): void
    {
        $provider = new FakeConstraintHandlerProvider('sql', [
            new ScopedHandler(new Mapper(static fn (mixed $v): mixed => $v), SignalKind::SQL_QUERY, 30),
        ]);
        $decision = new AuthorizationDecision(Decision::PERMIT, [['type' => 'sql']]);

        // SQL_QUERY not in the supported set: inadmissible, fails closed on DECISION.
        $plan = (new EnforcementPlanner([$provider]))->plan($decision, self::SUPPORTED);

        self::assertTrue($plan->execute(SignalKind::DECISION, new Present('d'), false)->failureState);
        self::assertSame([], $plan->entriesFor(SignalKind::SQL_QUERY));
    }

    public function testResourceSubstitutionMapsOutputToResource(): void
    {
        $decision = AuthorizationDecision::withResource(Decision::PERMIT, [], [], 'the-resource');
        $plan = (new EnforcementPlanner([]))->plan($decision, self::SUPPORTED);

        $result = $plan->execute(SignalKind::OUTPUT, new Present('original'), false);

        self::assertInstanceOf(Present::class, $result->value);
        self::assertSame('the-resource', $result->value->value);
    }

    public function testResourceWithoutOutputSignalFailsClosed(): void
    {
        $decision = AuthorizationDecision::withResource(Decision::PERMIT, [], [], 'r');
        $plan = (new EnforcementPlanner([]))->plan($decision, [SignalKind::DECISION]);

        self::assertTrue($plan->execute(SignalKind::DECISION, new Present('d'), false)->failureState);
    }

    public function testTwoMappersAtSamePriorityAndSignalAreReplacedWithFailures(): void
    {
        $mapperA = new ScopedHandler(new Mapper(static fn (mixed $v): mixed => is_string($v) ? $v.'a' : $v), SignalKind::OUTPUT, 0);
        $mapperB = new ScopedHandler(new Mapper(static fn (mixed $v): mixed => is_string($v) ? $v.'b' : $v), SignalKind::OUTPUT, 0);
        $plan = $this->plan(
            [['type' => 'a'], ['type' => 'b']],
            [],
            new FakeConstraintHandlerProvider('a', [$mapperA]),
            new FakeConstraintHandlerProvider('b', [$mapperB]),
        );

        $result = $plan->execute(SignalKind::OUTPUT, new Present('x'), false);

        self::assertTrue($result->failureState);
        // The non-commuting group was replaced, so no mapper transformed the value.
        self::assertInstanceOf(Present::class, $result->value);
        self::assertSame('x', $result->value->value);
    }

    public function testHandlersRunRunnerThenMapperThenConsumerAtEqualPriority(): void
    {
        $order = [];
        $handlers = [
            new ScopedHandler(new Consumer(static function () use (&$order): void {
                $order[] = 'consumer';
            }), SignalKind::OUTPUT, 0),
            new ScopedHandler(new Mapper(static function (mixed $v) use (&$order): mixed {
                $order[] = 'mapper';

                return $v;
            }), SignalKind::OUTPUT, 0),
            new ScopedHandler(new Runner(static function () use (&$order): void {
                $order[] = 'runner';
            }), SignalKind::OUTPUT, 0),
        ];
        $plan = $this->plan([['type' => 'multi']], [], new FakeConstraintHandlerProvider('multi', $handlers));

        $plan->execute(SignalKind::OUTPUT, new Present('v'), false);

        self::assertSame(['runner', 'mapper', 'consumer'], $order);
    }

    /**
     * @param list<mixed> $obligations
     * @param list<mixed> $advice
     */
    private function plan(array $obligations, array $advice, FakeConstraintHandlerProvider ...$providers): EnforcementPlan
    {
        $decision = new AuthorizationDecision(Decision::PERMIT, $obligations, $advice);

        return (new EnforcementPlanner(array_values($providers)))->plan($decision, self::SUPPORTED);
    }
}
