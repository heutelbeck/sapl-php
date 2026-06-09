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
 * A PDP whose `decide()` returns a caller-controllable decision stream and records
 * the subscription it was called with, so streaming-wiring tests can drive
 * decisions and assert how the subscription was built.
 */
final class RecordingStreamingPdp implements PolicyDecisionPoint
{
    public readonly ThroughStream $decisions;
    public ?AuthorizationSubscription $lastSubscription = null;

    public function __construct()
    {
        $this->decisions = new ThroughStream();
    }

    public function decideOnce(AuthorizationSubscription $subscription): AuthorizationDecision
    {
        return AuthorizationDecision::permit();
    }

    public function multiDecideAllOnce(MultiAuthorizationSubscription $subscription): MultiAuthorizationDecision
    {
        return new MultiAuthorizationDecision();
    }

    public function decide(AuthorizationSubscription $subscription): ReadableStreamInterface
    {
        $this->lastSubscription = $subscription;

        return $this->decisions;
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
