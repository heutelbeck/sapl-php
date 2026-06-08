<?php

declare(strict_types=1);

namespace Sapl\Api;

/**
 * A decision paired with the subscription id it belongs to.
 */
final class IdentifiableAuthorizationDecision
{
    public function __construct(
        public readonly string $subscriptionId,
        public readonly AuthorizationDecision $decision,
    ) {
    }
}
