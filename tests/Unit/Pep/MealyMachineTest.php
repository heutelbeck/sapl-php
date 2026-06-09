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
use Sapl\Pep\Streaming\EmitComplete;
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
use Sapl\Pep\Streaming\StepResult;
use Sapl\Pep\Streaming\Suspended;
use Sapl\Pep\Streaming\SuspendedReason;
use Sapl\Pep\Streaming\Terminated;

final class MealyMachineTest extends TestCase
{
    /**
     * The full transition table: (state, event) to (expected state class, emission tags).
     *
     * @return iterable<string, array{State, Event, class-string<State>, list<string>}>
     */
    public static function table(): iterable
    {
        $states = [
            'pending' => static fn (): State => Pending::instance(),
            'permitting' => static fn (): State => new Permitting(EnforcementPlan::empty()),
            'suspended' => static fn (): State => Suspended::instance(),
        ];
        // Per state: event label => [event factory, expected state, tags].
        $cells = [
            'pending' => [
                'permit' => [self::permit(), Permitting::class, ['granted']],
                'suspend' => [self::suspend(), Suspended::class, ['suspended']],
                'deny' => [self::deny(), Terminated::class, ['error']],
                'pdpError' => [new PdpError(new RuntimeException('x')), Terminated::class, ['error']],
                'itemPresent' => [self::rapPresent('v'), Pending::class, []],
                'itemAbsent' => [self::rapAbsent(), Pending::class, []],
                'itemFailed' => [self::rapFailed(), Terminated::class, ['error']],
                'rapError' => [new RapError(new RuntimeException('x')), Terminated::class, ['error']],
                'rapComplete' => [RapComplete::instance(), Terminated::class, ['complete']],
                'cancel' => [Cancel::instance(), Terminated::class, []],
            ],
            'permitting' => [
                'permit' => [self::permit(), Permitting::class, []],
                'suspend' => [self::suspend(), Suspended::class, ['suspended']],
                'deny' => [self::deny(), Terminated::class, ['error']],
                'pdpError' => [new PdpError(new RuntimeException('x')), Terminated::class, ['error']],
                'itemPresent' => [self::rapPresent('v'), Permitting::class, ['emit']],
                'itemAbsent' => [self::rapAbsent(), Permitting::class, []],
                'itemFailed' => [self::rapFailed(), Terminated::class, ['error']],
                'rapError' => [new RapError(new RuntimeException('x')), Terminated::class, ['error']],
                'rapComplete' => [RapComplete::instance(), Terminated::class, ['complete']],
                'cancel' => [Cancel::instance(), Terminated::class, []],
            ],
            'suspended' => [
                'permit' => [self::permit(), Permitting::class, ['granted']],
                'suspend' => [self::suspend(), Suspended::class, []],
                'deny' => [self::deny(), Terminated::class, ['error']],
                'pdpError' => [new PdpError(new RuntimeException('x')), Terminated::class, ['error']],
                'itemPresent' => [self::rapPresent('v'), Suspended::class, []],
                'itemAbsent' => [self::rapAbsent(), Suspended::class, []],
                'itemFailed' => [self::rapFailed(), Terminated::class, ['error']],
                'rapError' => [new RapError(new RuntimeException('x')), Terminated::class, ['error']],
                'rapComplete' => [RapComplete::instance(), Terminated::class, ['complete']],
                'cancel' => [Cancel::instance(), Terminated::class, []],
            ],
        ];

        foreach ($cells as $stateLabel => $events) {
            foreach ($events as $eventLabel => [$event, $expectedState, $tags]) {
                yield "{$stateLabel}/{$eventLabel}" => [$states[$stateLabel](), $event, $expectedState, $tags];
            }
        }
    }

    /**
     * @param class-string<State> $expectedState
     * @param list<string>        $expectedTags
     */
    #[DataProvider('table')]
    public function testTransition(State $state, Event $event, string $expectedState, array $expectedTags): void
    {
        $result = MealyMachine::step($state, $event);

        self::assertInstanceOf($expectedState, $result->state);
        self::assertSame($expectedTags, $this->tags($result));
    }

    public function testTerminatedAbsorbsEveryEvent(): void
    {
        foreach ([self::permit(), self::deny(), RapComplete::instance(), Cancel::instance()] as $event) {
            $result = MealyMachine::step(Terminated::instance(), $event);
            self::assertInstanceOf(Terminated::class, $result->state);
            self::assertSame([], $this->tags($result));
        }
    }

    public function testGrantedTransitionCarriesDecision(): void
    {
        $result = MealyMachine::step(Pending::instance(), self::permit());

        $emission = $result->emissions[0];
        self::assertInstanceOf(EmitTransition::class, $emission);
        self::assertInstanceOf(Granted::class, $emission->reason);
    }

    public function testSuspendedTransitionCarriesDecision(): void
    {
        $result = MealyMachine::step(Pending::instance(), self::suspend());

        $emission = $result->emissions[0];
        self::assertInstanceOf(EmitTransition::class, $emission);
        self::assertInstanceOf(SuspendedReason::class, $emission->reason);
    }

    public function testPermittingEmitsThePostMapperValue(): void
    {
        $item = new RapItem('raw', new EnforcementResult(new Present('mapped'), false));

        $result = MealyMachine::step(new Permitting(EnforcementPlan::empty()), $item);

        $emission = $result->emissions[0];
        self::assertInstanceOf(Emit::class, $emission);
        self::assertSame('mapped', $emission->value);
    }

    /**
     * @return iterable<string, array{DenyKind, string}>
     */
    public static function denyKinds(): iterable
    {
        yield 'indeterminate' => [DenyKind::INDETERMINATE, MealyMachine::DENIED_INDETERMINATE];
        yield 'not applicable' => [DenyKind::NO_POLICY_APPLICABLE, MealyMachine::DENIED_NO_POLICY_APPLICABLE];
        yield 'not enforceable' => [DenyKind::PERMIT_NOT_ENFORCEABLE, MealyMachine::DENIED_PERMIT_NOT_ENFORCEABLE];
        yield 'denied' => [DenyKind::POLICY_DENIED, MealyMachine::DENIED_BY_POLICY];
    }

    #[DataProvider('denyKinds')]
    public function testDenyMessagePerKind(DenyKind $kind, string $expectedMessage): void
    {
        $result = MealyMachine::step(Pending::instance(), new PdpDeny(AuthorizationDecision::deny(), EnforcementPlan::empty(), $kind));

        $emission = $result->emissions[0];
        self::assertInstanceOf(EmitError::class, $emission);
        self::assertSame($expectedMessage, $emission->error->getMessage());
    }

    /**
     * @return list<string>
     */
    private function tags(StepResult $result): array
    {
        return array_map(static fn (object $emission): string => match (true) {
            $emission instanceof Emit => 'emit',
            $emission instanceof EmitError => 'error',
            $emission instanceof EmitComplete => 'complete',
            $emission instanceof EmitTransition => $emission->reason instanceof Granted ? 'granted' : 'suspended',
            default => 'unknown',
        }, $result->emissions);
    }

    private static function permit(): PdpPermit
    {
        return new PdpPermit(AuthorizationDecision::permit(), EnforcementPlan::empty());
    }

    private static function suspend(): PdpSuspend
    {
        return new PdpSuspend(AuthorizationDecision::permit(), EnforcementPlan::empty());
    }

    private static function deny(): PdpDeny
    {
        return new PdpDeny(AuthorizationDecision::deny(), EnforcementPlan::empty(), DenyKind::POLICY_DENIED);
    }

    private static function rapPresent(mixed $value): RapItem
    {
        return new RapItem($value, new EnforcementResult(new Present($value), false));
    }

    private static function rapAbsent(): RapItem
    {
        return new RapItem(null, new EnforcementResult(Absent::instance(), false));
    }

    private static function rapFailed(): RapItem
    {
        return new RapItem(null, new EnforcementResult(Absent::instance(), true));
    }
}
