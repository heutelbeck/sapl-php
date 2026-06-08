<?php

declare(strict_types=1);

namespace Sapl\Pdp\Http;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sapl\Api\AuthorizationDecision;
use Sapl\Api\Decision;
use Sapl\Api\IdentifiableAuthorizationDecision;
use Sapl\Api\MultiAuthorizationDecision;

/**
 * Fail-closed decoding of PDP responses into the value model.
 *
 * A malformed or unrecognised payload never raises. Single decisions degrade
 * to `INDETERMINATE`; multi and identifiable decodes return null so the caller
 * can substitute an INDETERMINATE seed.
 */
final class DecisionParser
{
    public const int MAX_CONSTRAINT_COUNT = 100;

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function parseDecision(mixed $raw): AuthorizationDecision
    {
        if (!is_array($raw)) {
            $this->logger->warning('sapl.decision_response_not_object');

            return AuthorizationDecision::indeterminate();
        }
        $verb = $raw['decision'] ?? null;
        if (!is_string($verb)) {
            $this->logger->warning('sapl.decision_field_missing_or_not_string');

            return AuthorizationDecision::indeterminate();
        }
        $decision = Decision::tryFrom($verb);
        if (null === $decision) {
            $this->logger->warning('sapl.decision_field_invalid_value', ['value' => $verb]);

            return AuthorizationDecision::indeterminate();
        }
        $obligations = $this->constraintList($raw['obligations'] ?? null, 'obligations');
        $advice = $this->constraintList($raw['advice'] ?? null, 'advice');
        if (array_key_exists('resource', $raw)) {
            return AuthorizationDecision::withResource($decision, $obligations, $advice, $raw['resource']);
        }

        return new AuthorizationDecision($decision, $obligations, $advice);
    }

    public function parseMulti(mixed $raw): ?MultiAuthorizationDecision
    {
        if (!is_array($raw)) {
            $this->logger->warning('sapl.multi_response_not_object');

            return null;
        }
        $decisions = [];
        foreach ($raw as $subscriptionId => $value) {
            $decisions[(string) $subscriptionId] = $this->parseDecision($value);
        }

        return new MultiAuthorizationDecision($decisions);
    }

    public function parseIdentifiable(mixed $raw): ?IdentifiableAuthorizationDecision
    {
        if (!is_array($raw)) {
            $this->logger->warning('sapl.identifiable_response_not_object');

            return null;
        }
        $subscriptionId = $raw['subscriptionId'] ?? null;
        if (!is_string($subscriptionId) || '' === $subscriptionId) {
            $this->logger->warning('sapl.identifiable_subscription_id_invalid');

            return null;
        }

        return new IdentifiableAuthorizationDecision(
            $subscriptionId,
            $this->parseDecision($raw['decision'] ?? null),
        );
    }

    /**
     * @return list<mixed>
     */
    private function constraintList(mixed $value, string $label): array
    {
        if (!is_array($value)) {
            return [];
        }
        if (count($value) > self::MAX_CONSTRAINT_COUNT) {
            $this->logger->warning('sapl.constraint_array_oversized', [
                'label' => $label,
                'count' => count($value),
                'cap' => self::MAX_CONSTRAINT_COUNT,
            ]);
        }

        return array_values($value);
    }
}
