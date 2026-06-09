<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

use React\Stream\ReadableStreamInterface;
use Sapl\Pep\Constraints\EnforcementPlanner;
use Sapl\Pep\Constraints\SignalKind;

/**
 * The streaming PEP: enforces a protected stream against a continuous PDP decision
 * stream. Each call drives an independent {@see MealyMachine} via a
 * {@see StreamingEnforcementDriver} and returns the enforced output stream.
 */
final class StreamingPolicyEnforcementPoint
{
    /**
     * @param list<SignalKind> $supportedSignals
     */
    public function __construct(
        private readonly EnforcementPlanner $planner,
        private readonly array $supportedSignals,
    ) {
    }

    /**
     * @param ReadableStreamInterface $decisions a stream of {@see \Sapl\Api\AuthorizationDecision}
     * @param ReadableStreamInterface $rap       the protected method's item stream
     */
    public function enforce(
        ReadableStreamInterface $decisions,
        ReadableStreamInterface $rap,
        bool $signalTransitions = false,
    ): ReadableStreamInterface {
        $driver = new StreamingEnforcementDriver($this->planner, $this->supportedSignals);
        $subscription = new StreamingSubscription($driver, $decisions, $rap, $signalTransitions);

        return $subscription->start();
    }
}
