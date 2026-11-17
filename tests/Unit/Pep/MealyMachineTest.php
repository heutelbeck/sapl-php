<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Pep;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sapl\Api\AuthorizationDecision;
use Sapl\Api\Decision;
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
     * The shared five-language transition table: exact next state and ordered emission tags.
     *
     * @return iterable<string, array{State, Event, class-string<State>, list<string>}>
     */
    public static function table(): iterable
    {
        $lines = file(__DIR__.'/mealy-table.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (false === $lines || 'from,event,outcome,to,emissions' !== array_shift($lines)) {
            throw new RuntimeException('Unexpected Mealy table header.');
        }
        foreach ($lines as $line) {
            $columns = explode(',', $line);
            if (5 !== count($columns)) {
                throw new RuntimeException('Expected five Mealy table columns.');
            }
            [$from, $event, $outcome, $to, $emissions] = $columns;
            yield "{$from}/{$event}/{$outcome}" => [
                self::stateByName($from),
                self::eventByName($event, $outcome),
                self::stateByName($to)::class,
                '' === $emissions ? [] : explode('|', $emissions),
            ];
        }
    }

    public function testTableCoversEveryStateAndEventOutcomeExactlyOnce(): void
    {
        $states = ['Pending', 'Permitting', 'Suspended', 'Terminated'];
        $events = [
            'PdpPermit/', 'PdpSuspend/', 'PdpDeny/', 'PdpError/',
            'RapItem/Present', 'RapItem/Absent', 'RapItem/Failed',
            'RapError/', 'RapComplete/', 'Cancel/',
        ];
        $expected = [];
        foreach ($states as $state) {
            foreach ($events as $event) {
                $expected[] = "{$state}/{$event}";
            }
        }
        $actual = [];
        foreach (self::table() as $key => $row) {
            $actual[] = $key;
        }
        sort($expected);
        sort($actual);

        self::assertSame($expected, $actual);
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
            $emission instanceof Emit => 'EMIT_VALUE',
            $emission instanceof EmitError => 'EMIT_ERROR',
            $emission instanceof EmitComplete => 'EMIT_COMPLETE',
            $emission instanceof EmitTransition && $emission->reason instanceof Granted => 'EMIT_TRANSITION_GRANTED',
            $emission instanceof EmitTransition && $emission->reason instanceof SuspendedReason => 'EMIT_TRANSITION_SUSPENDED',
            default => throw new RuntimeException('Unknown Mealy emission.'),
        }, $result->emissions);
    }

    private static function stateByName(string $name): State
    {
        return match ($name) {
            'Pending' => Pending::instance(),
            'Permitting' => new Permitting(EnforcementPlan::empty()),
            'Suspended' => Suspended::instance(),
            'Terminated' => Terminated::instance(),
            default => throw new RuntimeException('Unknown Mealy state: '.$name),
        };
    }

    private static function eventByName(string $name, string $outcome): Event
    {
        return match ([$name, $outcome]) {
            ['PdpPermit', ''] => self::permit(),
            ['PdpSuspend', ''] => self::suspend(),
            ['PdpDeny', ''] => self::deny(),
            ['PdpError', ''] => new PdpError(new RuntimeException('pdp')),
            ['RapItem', 'Present'] => self::rapPresent('mapped'),
            ['RapItem', 'Absent'] => self::rapAbsent(),
            ['RapItem', 'Failed'] => self::rapFailed(),
            ['RapError', ''] => new RapError(new RuntimeException('rap')),
            ['RapComplete', ''] => RapComplete::instance(),
            ['Cancel', ''] => Cancel::instance(),
            default => throw new RuntimeException('Unknown Mealy event/outcome: '.$name.'/'.$outcome),
        };
    }

    private static function permit(): PdpPermit
    {
        return new PdpPermit(AuthorizationDecision::permit(), EnforcementPlan::empty());
    }

    private static function suspend(): PdpSuspend
    {
        return new PdpSuspend(new AuthorizationDecision(Decision::SUSPEND), EnforcementPlan::empty());
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
