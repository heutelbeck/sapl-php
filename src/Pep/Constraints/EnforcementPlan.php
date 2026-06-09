<?php

declare(strict_types=1);

namespace Sapl\Pep\Constraints;

use LogicException;
use Sapl\Pep\EnforcementResult;
use Sapl\Pep\Maybe;
use Sapl\Pep\Present;
use Throwable;

/**
 * The enforcement plan P(d) for an authorization decision: each signal mapped to
 * the ordered handler entries discharged when that signal fires.
 */
final class EnforcementPlan
{
    /**
     * @param array<string, list<EnforcementPlanEntry>> $entries keyed by SignalKind value
     */
    public function __construct(
        private readonly array $entries,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @return list<EnforcementPlanEntry>
     */
    public function entriesFor(SignalKind $signal): array
    {
        return $this->entries[$signal->value] ?? [];
    }

    /**
     * Discharge the entries scheduled for the signal in order, applying mappers,
     * consumers, and runners best-effort. A throwing handler is skipped; an
     * obligation handler that throws flips the failure state (false to true only,
     * seeded from the prior state). The caller supplies the initial carried value.
     */
    public function execute(SignalKind $signal, Maybe $initialValue, bool $priorFailureState): EnforcementResult
    {
        $current = $initialValue;
        $failureState = $priorFailureState;
        foreach ($this->entriesFor($signal) as $entry) {
            try {
                $current = $this->apply($entry->handler, $current);
            } catch (Throwable) {
                // Best-effort discharge: a throwing obligation handler fails closed
                // (flips the failure state), a throwing advice handler is ignored.
                if (ConstraintType::OBLIGATION === $entry->constraintType) {
                    $failureState = true;
                }
            }
        }

        return new EnforcementResult($current, $failureState);
    }

    private function apply(ConstraintHandler $handler, Maybe $current): Maybe
    {
        return match (true) {
            $handler instanceof Runner => $this->runAndPass($handler, $current),
            $handler instanceof Consumer => $this->consumeAndPass($handler, $current),
            $handler instanceof Mapper => $this->mapValue($handler, $current),
            default => throw new LogicException('Unknown constraint handler'),
        };
    }

    private function runAndPass(Runner $runner, Maybe $current): Maybe
    {
        ($runner->run)();

        return $current;
    }

    private function consumeAndPass(Consumer $consumer, Maybe $current): Maybe
    {
        if ($current instanceof Present) {
            ($consumer->accept)($current->value);
        }

        return $current;
    }

    private function mapValue(Mapper $mapper, Maybe $current): Maybe
    {
        if ($current instanceof Present) {
            return new Present(($mapper->apply)($current->value));
        }

        return $current;
    }
}
