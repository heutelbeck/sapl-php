<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Pep;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sapl\Api\AuthorizationDecision;
use Sapl\Pep\Absent;
use Sapl\Pep\Constraints\EnforcementPlan;
use Sapl\Pep\EnforcementResult;
use Sapl\Pep\Present;
use Sapl\Pep\Streaming\Cancel;
use Sapl\Pep\Streaming\DenyKind;
use Sapl\Pep\Streaming\Emit;
use Sapl\Pep\Streaming\EmitError;
use Sapl\Pep\Streaming\EmitTransition;
use Sapl\Pep\Streaming\Event;
use Sapl\Pep\Streaming\Granted;
use Sapl\Pep\Streaming\MealyMachine;
use Sapl\Pep\Streaming\PdpDeny;
use Sapl\Pep\Streaming\PdpError;
use Sapl\Pep\Streaming\PdpPermit;
use Sapl\Pep\Streaming\PdpSuspend;
use Sapl\Pep\Streaming\Pending;
use Sapl\Pep\Streaming\Permitting;
use Sapl\Pep\Streaming\RapComplete;
use Sapl\Pep\Streaming\RapError;
use Sapl\Pep\Streaming\RapItem;
use Sapl\Pep\Streaming\State;
use Sapl\Pep\Streaming\Suspended;
use Sapl\Pep\Streaming\Terminated;

/**
 * Lean-proof observation of the streaming automaton: universally-quantified
 * invariants over the whole (state, event) space, mirroring the reference
 * MealyMachineInvariantTests. The cell-table test ({@see MealyMachineTest})
 * covers each transition; these prove the load-bearing properties hold for all
 * states and events.
 */
final class MealyMachineInvariantTest extends TestCase
{
    /**
     * For every event, Terminated is absorbing and emits nothing.
     * Forall (e : Event), step(Terminated, e) = (Terminated, []).
     */
    #[DataProvider('allEvents')]
    public function testTerminatedIsAbsorbing(Event $event): void
    {
        $step = MealyMachine::step(Terminated::instance(), $event);

        self::assertInstanceOf(Terminated::class, $step->state);
        self::assertSame([], $step->emissions);
    }

    /**
     * From every non-terminated state, DENY terminates with one error.
     * Forall (s : State), s != Terminated -> step(s, PdpDeny) = (Terminated, [EmitError]).
     */
    #[DataProvider('nonTerminatedStates')]
    public function testDenyUniversallyTerminates(State $source): void
    {
        $step = MealyMachine::step($source, self::pdpDeny());

        self::assertInstanceOf(Terminated::class, $step->state);
        self::assertCount(1, $step->emissions);
        self::assertInstanceOf(EmitError::class, $step->emissions[0]);
    }

    /**
     * From every non-terminated state, PERMIT reaches Permitting.
     */
    #[DataProvider('nonTerminatedStates')]
    public function testPermitUniversallyReachesPermitting(State $source): void
    {
        self::assertInstanceOf(Permitting::class, MealyMachine::step($source, self::pdpPermit())->state);
    }

    /**
     * From every non-terminated state, SUSPEND reaches Suspended.
     */
    #[DataProvider('nonTerminatedStates')]
    public function testSuspendUniversallyReachesSuspended(State $source): void
    {
        self::assertInstanceOf(Suspended::class, MealyMachine::step($source, self::pdpSuspend())->state);
    }

    /**
     * From every non-terminated state, each lifecycle terminator reaches Terminated.
     */
    #[DataProvider('nonTerminatedStateAndLifecycleTerminator')]
    public function testLifecycleTerminatorsReachTerminated(State $source, Event $event): void
    {
        self::assertInstanceOf(Terminated::class, MealyMachine::step($source, $event)->state);
    }

    /**
     * No item is ever emitted while Suspended, for any item outcome.
     */
    #[DataProvider('itemOutcomes')]
    public function testNoEmitInSuspended(Event $item): void
    {
        self::assertEmissionsContainNoEmit(MealyMachine::step(Suspended::instance(), $item)->emissions);
    }

    /**
     * No item is ever emitted while Pending, for any item outcome.
     */
    #[DataProvider('itemOutcomes')]
    public function testNoEmitInPending(Event $item): void
    {
        self::assertEmissionsContainNoEmit(MealyMachine::step(Pending::instance(), $item)->emissions);
    }

    /**
     * A per-item obligation failure terminates from every non-terminated state
     * (strict fail-closed: universal fulfillment-failure termination).
     */
    #[DataProvider('nonTerminatedStates')]
    public function testItemFailureUniversallyTerminates(State $source): void
    {
        $step = MealyMachine::step($source, self::rapItemFailed());

        self::assertInstanceOf(Terminated::class, $step->state);
        self::assertCount(1, $step->emissions);
        self::assertInstanceOf(EmitError::class, $step->emissions[0]);
    }

    public function testReplanIsSilent(): void
    {
        self::assertSame([], MealyMachine::step(self::permitting(), self::pdpPermit())->emissions);
    }

    public function testReSuspendIsSilent(): void
    {
        self::assertSame([], MealyMachine::step(Suspended::instance(), self::pdpSuspend())->emissions);
    }

    public function testInitialPermitEmitsGrantedBoundary(): void
    {
        self::assertSingleGrantedBoundary(MealyMachine::step(Pending::instance(), self::pdpPermit())->emissions);
    }

    public function testResumePermitEmitsGrantedBoundary(): void
    {
        self::assertSingleGrantedBoundary(MealyMachine::step(Suspended::instance(), self::pdpPermit())->emissions);
    }

    public function testPendingToSuspendedEmitsBoundary(): void
    {
        $emissions = MealyMachine::step(Pending::instance(), self::pdpSuspend())->emissions;

        self::assertCount(1, $emissions);
        self::assertInstanceOf(EmitTransition::class, $emissions[0]);
    }

    public function testPermittingToSuspendedEmitsBoundary(): void
    {
        $emissions = MealyMachine::step(self::permitting(), self::pdpSuspend())->emissions;

        self::assertCount(1, $emissions);
        self::assertInstanceOf(EmitTransition::class, $emissions[0]);
    }

    /**
     * Replay [PERMIT, failed item] from Pending ends Terminated.
     */
    public function testPermitThenFailedItemTerminates(): void
    {
        $afterPermit = MealyMachine::step(Pending::instance(), self::pdpPermit());
        $afterItem = MealyMachine::step($afterPermit->state, self::rapItemFailed());

        self::assertInstanceOf(Terminated::class, $afterItem->state);
    }

    /**
     * @param list<object> $emissions
     */
    private static function assertEmissionsContainNoEmit(array $emissions): void
    {
        $emits = array_values(array_filter($emissions, static fn (object $emission): bool => $emission instanceof Emit));

        self::assertSame([], $emits);
    }

    /**
     * @param list<object> $emissions
     */
    private static function assertSingleGrantedBoundary(array $emissions): void
    {
        self::assertCount(1, $emissions);
        $emission = $emissions[0];
        self::assertInstanceOf(EmitTransition::class, $emission);
        self::assertInstanceOf(Granted::class, $emission->reason);
    }

    /**
     * @return iterable<string, array{State}>
     */
    public static function nonTerminatedStates(): iterable
    {
        yield 'Pending' => [Pending::instance()];
        yield 'Permitting' => [self::permitting()];
        yield 'Suspended' => [Suspended::instance()];
    }

    /**
     * @return iterable<string, array{Event}>
     */
    public static function allEvents(): iterable
    {
        yield 'PdpPermit' => [self::pdpPermit()];
        yield 'PdpSuspend' => [self::pdpSuspend()];
        yield 'PdpDeny' => [self::pdpDeny()];
        yield 'PdpError' => [new PdpError(new RuntimeException('x'))];
        yield 'RapItem-Present' => [self::rapItemPresent()];
        yield 'RapItem-Absent' => [self::rapItemAbsent()];
        yield 'RapItem-Failed' => [self::rapItemFailed()];
        yield 'RapError' => [new RapError(new RuntimeException('x'))];
        yield 'RapComplete' => [RapComplete::instance()];
        yield 'Cancel' => [Cancel::instance()];
    }

    /**
     * @return iterable<string, array{Event}>
     */
    public static function itemOutcomes(): iterable
    {
        yield 'Present' => [self::rapItemPresent()];
        yield 'Absent' => [self::rapItemAbsent()];
        yield 'Failed' => [self::rapItemFailed()];
    }

    /**
     * @return iterable<string, array{State, Event}>
     */
    public static function nonTerminatedStateAndLifecycleTerminator(): iterable
    {
        $states = [
            'Pending' => Pending::instance(),
            'Permitting' => self::permitting(),
            'Suspended' => Suspended::instance(),
        ];
        $terminators = [
            'Cancel' => Cancel::instance(),
            'RapComplete' => RapComplete::instance(),
            'RapError' => new RapError(new RuntimeException('x')),
            'PdpError' => new PdpError(new RuntimeException('x')),
        ];
        foreach ($states as $stateName => $state) {
            foreach ($terminators as $eventName => $event) {
                yield "{$stateName}/{$eventName}" => [$state, $event];
            }
        }
    }

    private static function permitting(): Permitting
    {
        return new Permitting(EnforcementPlan::empty());
    }

    private static function pdpPermit(): PdpPermit
    {
        return new PdpPermit(AuthorizationDecision::permit(), EnforcementPlan::empty());
    }

    private static function pdpSuspend(): PdpSuspend
    {
        return new PdpSuspend(AuthorizationDecision::permit(), EnforcementPlan::empty());
    }

    private static function pdpDeny(): PdpDeny
    {
        return new PdpDeny(AuthorizationDecision::deny(), EnforcementPlan::empty(), DenyKind::POLICY_DENIED);
    }

    private static function rapItemPresent(): RapItem
    {
        return new RapItem('v', new EnforcementResult(new Present('v'), false));
    }

    private static function rapItemAbsent(): RapItem
    {
        return new RapItem(null, new EnforcementResult(Absent::instance(), false));
    }

    private static function rapItemFailed(): RapItem
    {
        return new RapItem(null, new EnforcementResult(Absent::instance(), true));
    }
}
