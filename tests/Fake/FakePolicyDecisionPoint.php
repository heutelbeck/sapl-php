<?php

declare(strict_types=1);

namespace Sapl\Tests\Fake;

use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;
use Sapl\Api\AuthorizationDecision;
use Sapl\Api\AuthorizationSubscription;
use Sapl\Api\MultiAuthorizationDecision;
use Sapl\Api\MultiAuthorizationSubscription;
use Sapl\Pdp\PolicyDecisionPoint;

/**
 * Returns a fixed decision for one-shot requests. Streaming methods return an
 * empty stream; they are unused by the blocking PEP tests.
 */
final class FakePolicyDecisionPoint implements PolicyDecisionPoint
{
    public int $decideOnceCalls = 0;

    public function __construct(
        private readonly AuthorizationDecision $decision,
    ) {
    }

    public function decideOnce(AuthorizationSubscription $subscription): AuthorizationDecision
    {
        ++$this->decideOnceCalls;

        return $this->decision;
    }

    public function multiDecideAllOnce(MultiAuthorizationSubscription $subscription): MultiAuthorizationDecision
    {
        return new MultiAuthorizationDecision();
    }

    public function decide(AuthorizationSubscription $subscription): ReadableStreamInterface
    {
        return new ThroughStream();
    }

    public function multiDecide(MultiAuthorizationSubscription $subscription): ReadableStreamInterface
    {
        return new ThroughStream();
    }

    public function multiDecideAll(MultiAuthorizationSubscription $subscription): ReadableStreamInterface
    {
        return new ThroughStream();
    }

    public function close(): void
    {
    }
}
