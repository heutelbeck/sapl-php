<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Pep;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sapl\Pep\Absent;
use Sapl\Pep\Constraints\ConstraintType;
use Sapl\Pep\Constraints\Consumer;
use Sapl\Pep\Constraints\EnforcementPlan;
use Sapl\Pep\Constraints\EnforcementPlanEntry;
use Sapl\Pep\Constraints\Mapper;
use Sapl\Pep\Constraints\Runner;
use Sapl\Pep\Constraints\SignalKind;
use Sapl\Pep\Present;

final class EnforcementPlanTest extends TestCase
{
    public function testMapperTransformsPresentValue(): void
    {
        $plan = $this->planFor(SignalKind::OUTPUT, new Mapper(static fn (mixed $v): mixed => is_string($v) ? $v.'!' : $v));

        $result = $plan->execute(SignalKind::OUTPUT, new Present('hi'), false);

        self::assertInstanceOf(Present::class, $result->value);
        self::assertSame('hi!', $result->value->value);
        self::assertFalse($result->failureState);
    }

    public function testConsumerObservesAndPassesThrough(): void
    {
        $seen = null;
        $plan = $this->planFor(SignalKind::OUTPUT, new Consumer(static function (mixed $v) use (&$seen): void {
            $seen = $v;
        }));

        $result = $plan->execute(SignalKind::OUTPUT, new Present('x'), false);

        self::assertSame('x', $seen);
        self::assertInstanceOf(Present::class, $result->value);
        self::assertSame('x', $result->value->value);
    }

    public function testRunnerRunsEvenForAbsentValue(): void
    {
        $ran = false;
        $plan = $this->planFor(SignalKind::COMPLETE, new Runner(static function () use (&$ran): void {
            $ran = true;
        }));

        $result = $plan->execute(SignalKind::COMPLETE, Absent::instance(), false);

        self::assertTrue($ran);
        self::assertInstanceOf(Absent::class, $result->value);
    }

    public function testMapperAndConsumerSkippedForAbsentValue(): void
    {
        $touched = false;
        $plan = $this->planFor(SignalKind::OUTPUT, new Mapper(static function (mixed $v) use (&$touched): mixed {
            $touched = true;

            return $v;
        }));

        $result = $plan->execute(SignalKind::OUTPUT, Absent::instance(), false);

        self::assertFalse($touched);
        self::assertInstanceOf(Absent::class, $result->value);
    }

    public function testObligationFailureFlipsFailureState(): void
    {
        $plan = $this->planFor(
            SignalKind::DECISION,
            new Runner(static fn () => throw new RuntimeException('boom')),
            ConstraintType::OBLIGATION,
        );

        $result = $plan->execute(SignalKind::DECISION, new Present('d'), false);

        self::assertTrue($result->failureState);
    }

    public function testAdviceFailureDoesNotFlipFailureState(): void
    {
        $plan = $this->planFor(
            SignalKind::DECISION,
            new Runner(static fn () => throw new RuntimeException('boom')),
            ConstraintType::ADVICE,
        );

        $result = $plan->execute(SignalKind::DECISION, new Present('d'), false);

        self::assertFalse($result->failureState);
    }

    public function testPriorFailureStateIsPreserved(): void
    {
        $result = EnforcementPlan::empty()->execute(SignalKind::DECISION, new Present('d'), true);

        self::assertTrue($result->failureState);
    }

    public function testEntriesExecuteInListOrder(): void
    {
        $plan = new EnforcementPlan([
            SignalKind::OUTPUT->value => [
                new EnforcementPlanEntry(new Mapper(static fn (mixed $v): mixed => is_string($v) ? $v.'a' : $v), 0, ConstraintType::OBLIGATION, []),
                new EnforcementPlanEntry(new Mapper(static fn (mixed $v): mixed => is_string($v) ? $v.'b' : $v), 1, ConstraintType::OBLIGATION, []),
            ],
        ]);

        $result = $plan->execute(SignalKind::OUTPUT, new Present(''), false);

        self::assertInstanceOf(Present::class, $result->value);
        self::assertSame('ab', $result->value->value);
    }

    private function planFor(
        SignalKind $signal,
        Mapper|Consumer|Runner $handler,
        ConstraintType $type = ConstraintType::OBLIGATION,
    ): EnforcementPlan {
        return new EnforcementPlan([
            $signal->value => [new EnforcementPlanEntry($handler, 0, $type, [])],
        ]);
    }
}
