<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

use LogicException;
use Sapl\Pep\AccessDeniedException;
use Sapl\Pep\Present;
use Throwable;

/**
 * The streaming PEP's Mealy machine: the pure, total combined transition and
 * output function S x Sigma -> S x Lambda. Routing dispatches on the PDP decision
 * verb carried by the event and the current state. Explicit DENY always
 * terminates, SUSPEND always transitions to {@see Suspended}, and a per-item
 * obligation failure terminates under strict fail-closed. No side effects, no I/O.
 */
final class MealyMachine
{
    public const string DENIED_BY_OBLIGATION_FAILURE = 'Access denied: a per-item obligation handler failed.';
    public const string DENIED_BY_POLICY = 'Access denied by policy.';
    public const string DENIED_INDETERMINATE = 'Access denied: policy evaluation produced an indeterminate result.';
    public const string DENIED_NO_POLICY_APPLICABLE = 'Access denied: no applicable policy found.';
    public const string DENIED_PERMIT_NOT_ENFORCEABLE = 'Access denied: decision-scoped enforcement of permit failed.';

    private function __construct()
    {
    }

    /**
     * Compute the next state and emissions for a single (state, event) pair.
     */
    public static function step(State $state, Event $event): StepResult
    {
        if ($state instanceof Terminated) {
            return StepResult::to($state);
        }

        return match (true) {
            $event instanceof Cancel => StepResult::to(Terminated::instance()),
            $event instanceof RapComplete => StepResult::to(Terminated::instance(), EmitComplete::instance()),
            $event instanceof RapError => self::terminateWithError($event->error),
            $event instanceof PdpError => self::terminateWithError($event->error),
            $event instanceof PdpPermit => self::onPermit($state, $event),
            $event instanceof PdpSuspend => self::onSuspend($state, $event),
            $event instanceof PdpDeny => self::onDeny($event),
            $event instanceof RapItem => self::onItem($state, $event),
            default => throw new LogicException('Unknown streaming event'),
        };
    }

    private static function onPermit(State $state, PdpPermit $permit): StepResult
    {
        $next = new Permitting($permit->plan);
        // Plan replacement while permitting is silent; initial grant and resume
        // emit the Granted boundary signal.
        if ($state instanceof Permitting) {
            return StepResult::to($next);
        }

        return StepResult::to($next, new EmitTransition(new Granted($permit->decision)));
    }

    private static function onSuspend(State $state, PdpSuspend $suspend): StepResult
    {
        // Re-suspend while suspended is silent; the boundary already occurred.
        if ($state instanceof Suspended) {
            return StepResult::to(Suspended::instance());
        }

        return StepResult::to(Suspended::instance(), new EmitTransition(new SuspendedReason($suspend->decision)));
    }

    private static function onDeny(PdpDeny $deny): StepResult
    {
        $message = match ($deny->kind) {
            DenyKind::INDETERMINATE => self::DENIED_INDETERMINATE,
            DenyKind::NO_POLICY_APPLICABLE => self::DENIED_NO_POLICY_APPLICABLE,
            DenyKind::PERMIT_NOT_ENFORCEABLE => self::DENIED_PERMIT_NOT_ENFORCEABLE,
            DenyKind::POLICY_DENIED => self::DENIED_BY_POLICY,
        };

        return StepResult::to(Terminated::instance(), new EmitError(new AccessDeniedException($message)));
    }

    private static function onItem(State $state, RapItem $item): StepResult
    {
        // A per-item obligation failure terminates from any non-terminated state.
        if ($item->enforcementResult->failureState) {
            return StepResult::to(
                Terminated::instance(),
                new EmitError(new AccessDeniedException(self::DENIED_BY_OBLIGATION_FAILURE)),
            );
        }

        return match (true) {
            $state instanceof Pending => StepResult::to($state),
            $state instanceof Suspended => StepResult::to($state),
            $state instanceof Permitting => self::permittingItem($state, $item),
            default => throw new LogicException('Unreachable streaming state'),
        };
    }

    private static function permittingItem(Permitting $state, RapItem $item): StepResult
    {
        if ($item->enforcementResult->value instanceof Present) {
            return StepResult::to($state, new Emit($item->enforcementResult->value->value));
        }

        // Absent means the mapper dropped the item; no observable output.
        return StepResult::to($state);
    }

    private static function terminateWithError(Throwable $error): StepResult
    {
        return StepResult::to(Terminated::instance(), new EmitError($error));
    }
}
