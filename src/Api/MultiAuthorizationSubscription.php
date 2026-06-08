<?php

declare(strict_types=1);

namespace Sapl\Api;

/**
 * A bundle of named authorization subscriptions for multi-decision requests.
 */
final class MultiAuthorizationSubscription
{
    /**
     * @param array<string, AuthorizationSubscription> $subscriptions
     */
    public function __construct(
        public readonly array $subscriptions = [],
    ) {
    }

    /**
     * Serialize for transmission to the PDP.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (AuthorizationSubscription $subscription): array => $subscription->toArray(),
            $this->subscriptions,
        );
    }

    /**
     * @return list<string>
     */
    public function subscriptionIds(): array
    {
        return array_keys($this->subscriptions);
    }
}
