<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

use Sapl\Api\AuthorizationDecision;
use Sapl\Api\Decision;
use Sapl\Pep\Constraints\EnforcementPlan;
use Sapl\Pep\Constraints\EnforcementPlanner;
use Sapl\Pep\Constraints\SignalKind;
use Sapl\Pep\EnforcementResult;
use Sapl\Pep\Present;
use Throwable;

/**
 * Drives the streaming {@see MealyMachine} from raw PDP decisions and RAP items.
 *
 * Pure and synchronous: it holds the current state, classifies each decision into
 * a verb-specific event (running decision-scoped enforcement for a PERMIT, which
 * may downgrade it to a deny), runs per-item output enforcement against the
 * current permitting plan, steps the machine, and returns the emissions. The
 * transport adapter feeds it events and renders the emissions.
 */
final class StreamingEnforcementDriver
{
    private State $state;

    /**
     * @param list<SignalKind> $supportedSignals
     */
    public function __construct(
        private readonly EnforcementPlanner $planner,
        private readonly array $supportedSignals,
    ) {
        $this->state = Pending::instance();
    }

    public function isTerminated(): bool
    {
        return $this->state instanceof Terminated;
    }

    /**
     * @return list<Emission>
     */
    public function onDecision(AuthorizationDecision $decision): array
    {
        $plan = $this->planner->plan($decision, $this->supportedSignals);
        $event = match ($decision->decision) {
            Decision::PERMIT => $this->classifyPermit($decision, $plan),
            Decision::SUSPEND => new PdpSuspend($decision, $plan),
            Decision::DENY => new PdpDeny($decision, $plan, DenyKind::POLICY_DENIED),
            Decision::INDETERMINATE => new PdpDeny($decision, $plan, DenyKind::INDETERMINATE),
            Decision::NOT_APPLICABLE => new PdpDeny($decision, $plan, DenyKind::NO_POLICY_APPLICABLE),
        };

        return $this->apply($event);
    }

    /**
     * @return list<Emission>
     */
    public function onItem(mixed $item): array
    {
        $result = $this->state instanceof Permitting
            ? $this->state->plan->execute(SignalKind::OUTPUT, new Present($item), false)
            : new EnforcementResult(new Present($item), false);

        return $this->apply(new RapItem($item, $result));
    }

    /**
     * @return list<Emission>
     */
    public function onRapComplete(): array
    {
        return $this->apply(RapComplete::instance());
    }

    /**
     * @return list<Emission>
     */
    public function onRapError(Throwable $error): array
    {
        return $this->apply(new RapError($error));
    }

    /**
     * @return list<Emission>
     */
    public function onPdpError(Throwable $error): array
    {
        return $this->apply(new PdpError($error));
    }

    /**
     * @return list<Emission>
     */
    public function onCancel(): array
    {
        return $this->apply(Cancel::instance());
    }

    private function classifyPermit(AuthorizationDecision $decision, EnforcementPlan $plan): Event
    {
        $failed = $plan->execute(SignalKind::DECISION, new Present($decision), false)->failureState;

        return $failed
            ? new PdpDeny($decision, $plan, DenyKind::PERMIT_NOT_ENFORCEABLE)
            : new PdpPermit($decision, $plan);
    }

    /**
     * @return list<Emission>
     */
    private function apply(Event $event): array
    {
        $result = MealyMachine::step($this->state, $event);
        $this->state = $result->state;

        return $result->emissions;
    }
}
