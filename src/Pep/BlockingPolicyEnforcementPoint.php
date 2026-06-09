<?php

declare(strict_types=1);

namespace Sapl\Pep;

use Sapl\Api\AuthorizationSubscription;
use Sapl\Api\Decision;
use Sapl\Pdp\PolicyDecisionPoint;
use Sapl\Pep\Constraints\EnforcementPlan;
use Sapl\Pep\Constraints\EnforcementPlanner;
use Sapl\Pep\Constraints\SignalKind;
use Throwable;

/**
 * The one-shot (non-streaming) PEP for pre- and post-invocation enforcement.
 *
 * SUSPEND is treated identically to DENY in a one-shot context: any decision
 * other than PERMIT denies. Obligation-handler failures fail closed. The protected
 * method is invoked only after a pre-invocation PERMIT with no obligation failure.
 */
final class BlockingPolicyEnforcementPoint
{
    private const string ERROR_NOT_PERMIT = 'Access denied: the PDP decision was %s, not PERMIT.';
    private const string ERROR_PRE_INVOCATION_OBLIGATION_FAILED = 'Access denied: a pre-invocation obligation handler failed. The protected method was not invoked.';
    private const string ERROR_POST_INVOCATION_OBLIGATION_FAILED = 'Access denied: a post-invocation obligation handler failed after the protected method had already executed.';
    private const string ERROR_OBLIGATION_FAILED = 'Access denied: an obligation handler failed.';

    public function __construct(
        private readonly PolicyDecisionPoint $pdp,
        private readonly EnforcementPlanner $planner,
    ) {
    }

    /**
     * Enforce before the protected method runs. Fires the decision and input
     * signals, denies on a non-PERMIT decision or an obligation failure, invokes the
     * method, then fires the output signal on the result. Returns the (possibly
     * transformed) result; throws on denial or a failed obligation.
     *
     * @param list<SignalKind> $supportedSignals
     */
    public function preEnforce(
        AuthorizationSubscription $subscription,
        array $supportedSignals,
        MethodInvocation $invocation,
    ): mixed {
        $decision = $this->pdp->decideOnce($subscription);
        $plan = $this->planner->plan($decision, $supportedSignals);
        try {
            $failed = $plan->execute(SignalKind::DECISION, new Present($decision), false)->failureState;
            // The input result value is ignored: input mappers mutate the invocation
            // in place, so only the failure state matters here.
            $failed = $plan->execute(SignalKind::INPUT, new Present($invocation), $failed)->failureState;

            if (Decision::PERMIT !== $decision->decision) {
                throw new AccessDeniedException(sprintf(self::ERROR_NOT_PERMIT, $decision->decision->value));
            }
            if ($failed) {
                throw new AccessDeniedException(self::ERROR_PRE_INVOCATION_OBLIGATION_FAILED);
            }

            return $this->enforceOutput($plan, $invocation->proceed(), false);
        } catch (Throwable $throwable) {
            throw $this->enforceError($plan, $throwable);
        }
    }

    /**
     * Enforce after the protected method has produced a value. The caller runs the
     * method first (its exceptions propagate unmapped, since the decision needs the
     * result), then passes the value here. Fires the decision signal, denies on a
     * non-PERMIT decision, then fires the output signal threading the decision
     * failure state. Returns the (possibly transformed) value; throws on denial.
     *
     * @param list<SignalKind> $supportedSignals
     */
    public function postEnforce(
        AuthorizationSubscription $subscription,
        array $supportedSignals,
        mixed $value,
    ): mixed {
        $decision = $this->pdp->decideOnce($subscription);
        $plan = $this->planner->plan($decision, $supportedSignals);
        try {
            $failed = $plan->execute(SignalKind::DECISION, new Present($decision), false)->failureState;

            if (Decision::PERMIT !== $decision->decision) {
                throw new AccessDeniedException(sprintf(self::ERROR_NOT_PERMIT, $decision->decision->value));
            }

            return $this->enforceOutput($plan, $value, $failed);
        } catch (Throwable $throwable) {
            throw $this->enforceError($plan, $throwable);
        }
    }

    private function enforceOutput(EnforcementPlan $plan, mixed $value, bool $priorFailure): mixed
    {
        $result = $plan->execute(SignalKind::OUTPUT, new Present($value), $priorFailure);
        if ($result->failureState) {
            throw new AccessDeniedException(self::ERROR_POST_INVOCATION_OBLIGATION_FAILED);
        }

        return $result->value instanceof Present ? $result->value->value : null;
    }

    /**
     * Fire the error signal for the throwable and resolve the throwable to raise: a
     * failed error obligation escalates to a fresh denial; an error mapper that
     * returned a throwable replaces it; otherwise it passes through unchanged.
     */
    private function enforceError(EnforcementPlan $plan, Throwable $throwable): Throwable
    {
        try {
            $errorResult = $plan->execute(SignalKind::ERROR, new Present($throwable), false);
        } catch (Throwable $handlerFailure) {
            return $handlerFailure;
        }
        if ($errorResult->failureState) {
            return new AccessDeniedException(self::ERROR_OBLIGATION_FAILED);
        }
        if ($errorResult->value instanceof Present && $errorResult->value->value instanceof Throwable) {
            return $errorResult->value->value;
        }

        return $throwable;
    }
}
