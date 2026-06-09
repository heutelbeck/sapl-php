<?php

declare(strict_types=1);

namespace Sapl\Tests\Integration\Kernel;

use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;
use Sapl\Api\AuthorizationDecision;
use Sapl\Api\AuthorizationSubscription;
use Sapl\Api\MultiAuthorizationDecision;
use Sapl\Api\MultiAuthorizationSubscription;
use Sapl\Pdp\PolicyDecisionPoint;

/**
 * A PDP whose one-shot decision is set per test, so the kernel functional test
 * can drive permit and deny without a real PDP.
 */
final class ConfigurableFakePdp implements PolicyDecisionPoint
{
    public AuthorizationDecision $decision;
    public readonly ThroughStream $decisions;

    public function __construct()
    {
        $this->decision = AuthorizationDecision::permit();
        $this->decisions = new ThroughStream();
    }

    public function decideOnce(AuthorizationSubscription $subscription): AuthorizationDecision
    {
        return $this->decision;
    }

    public function multiDecideAllOnce(MultiAuthorizationSubscription $subscription): MultiAuthorizationDecision
    {
        return new MultiAuthorizationDecision();
    }

    public function decide(AuthorizationSubscription $subscription): ReadableStreamInterface
    {
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
