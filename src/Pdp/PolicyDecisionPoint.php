<?php

declare(strict_types=1);

namespace Sapl\Pdp;

use React\Stream\ReadableStreamInterface;
use Sapl\Api\AuthorizationDecision;
use Sapl\Api\AuthorizationSubscription;
use Sapl\Api\MultiAuthorizationDecision;
use Sapl\Api\MultiAuthorizationSubscription;

/**
 * Transport-agnostic surface for SAPL PDP communication.
 *
 * Fail-closed contract: when the PDP is unreachable, returns a response that
 * rejects (`INDETERMINATE`) rather than raising. One-shot methods return an
 * `INDETERMINATE` decision; streaming methods emit `INDETERMINATE` and never
 * terminate on a transport condition. See the PDP client resilience contract.
 */
interface PolicyDecisionPoint
{
    /**
     * Single one-shot authorization request.
     *
     * Returns the PDP decision, or `INDETERMINATE` on any transport or parse
     * failure. Never throws; never retries.
     */
    public function decideOnce(AuthorizationSubscription $subscription): AuthorizationDecision;

    /**
     * One-shot multi-subscription request returning all decisions at once.
     *
     * Returns the decisions, or an empty {@see MultiAuthorizationDecision} on
     * transport or parse failure. Never throws; never retries.
     */
    public function multiDecideAllOnce(MultiAuthorizationSubscription $subscription): MultiAuthorizationDecision;

    /**
     * Subscribe to a continuous decision stream for one subscription.
     *
     * The returned stream emits {@see AuthorizationDecision} values on the
     * `data` event, suppressing consecutive duplicates. It reconnects on any
     * transport failure or graceful server completion, emitting `INDETERMINATE`
     * across the gap, and ends only when the consumer closes it.
     */
    public function decide(AuthorizationSubscription $subscription): ReadableStreamInterface;

    /**
     * Multi-subscription stream where decisions arrive individually as
     * {@see \Sapl\Api\IdentifiableAuthorizationDecision} values.
     */
    public function multiDecide(MultiAuthorizationSubscription $subscription): ReadableStreamInterface;

    /**
     * Multi-subscription stream where each emission is a snapshot of all
     * decisions as a {@see MultiAuthorizationDecision}.
     */
    public function multiDecideAll(MultiAuthorizationSubscription $subscription): ReadableStreamInterface;

    /**
     * Release transport resources. After close the client must not be reused.
     */
    public function close(): void;
}
