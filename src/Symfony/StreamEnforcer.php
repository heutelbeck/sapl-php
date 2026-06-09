<?php

declare(strict_types=1);

namespace Sapl\Symfony;

use LogicException;
use React\Stream\ReadableStreamInterface;
use Sapl\Pdp\PolicyDecisionPoint;
use Sapl\Pep\Streaming\StreamingPolicyEnforcementPoint;

use function sprintf;

/**
 * Enforces `#[StreamEnforce]` over a method's item stream.
 *
 * Builds the subscription, opens the continuous PDP decision stream for it, and
 * returns the enforced item stream produced by the streaming PEP. The result is a
 * plain readable stream of enforced items and boundary signals; how it is rendered
 * to a client (a server-sent-event response, a websocket, an internal consumer) is
 * the caller's concern, not this layer's.
 */
final class StreamEnforcer
{
    private const string ERROR_NOT_A_STREAM = 'A #[StreamEnforce] method must return a %s; %s::%s() returned %s.';

    public function __construct(
        private readonly PolicyDecisionPoint $pdp,
        private readonly StreamingPolicyEnforcementPoint $pep,
        private readonly AuthorizationSubscriptionBuilder $builder,
    ) {
    }

    /**
     * @param array<string, mixed> $namedArgs
     * @param mixed                $rap       the protected method's return value, expected to be a stream
     */
    public function enforce(
        StreamEnforce $attribute,
        string $class,
        string $method,
        array $namedArgs,
        mixed $rap,
    ): ReadableStreamInterface {
        if (!$rap instanceof ReadableStreamInterface) {
            throw new LogicException(sprintf(self::ERROR_NOT_A_STREAM, ReadableStreamInterface::class, $class, $method, get_debug_type($rap)));
        }
        $subscription = $this->builder->forInvocation($attribute, $class, $method, $namedArgs);
        $decisions = $this->pdp->decide($subscription);

        return $this->pep->enforce(
            $decisions,
            $rap,
            $attribute->signalTransitions,
            $attribute->pauseRapDuringSuspend,
        );
    }
}
