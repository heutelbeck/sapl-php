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

    /**
     * Decode a multi-decision response from its raw JSON body, rejecting a
     * repeated subscription id fail-closed. A duplicate id is an error, never a
     * last-wins merge, so a later PERMIT can never erase an earlier DENY for the
     * same id. Duplicate top-level keys are lost once the body is run through
     * json_decode, so the check works on the raw text before decoding.
     */
    public function parseMultiJson(string $json): ?MultiAuthorizationDecision
    {
        $entryCount = $this->countTopLevelObjectEntries($json);
        if (null === $entryCount) {
            $this->logger->warning('sapl.multi_response_not_object');

            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            $this->logger->warning('sapl.multi_response_not_object');

            return null;
        }
        if ($entryCount !== count($decoded)) {
            $this->logger->warning('sapl.multi_response_duplicate_subscription_id');

            return null;
        }

        return $this->parseMulti($decoded);
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
     * Count the entries of the root JSON object including any with a repeated
     * key, so a duplicate can be detected by comparing against the unique-keyed
     * count produced by json_decode. Returns null when the payload is not a JSON
     * object. Nested objects and arrays are skipped; only root keys are counted.
     */
    private function countTopLevelObjectEntries(string $json): ?int
    {
        $length = strlen($json);
        $i = 0;
        while ($i < $length && ctype_space($json[$i])) {
            ++$i;
        }
        if ($i >= $length || '{' !== $json[$i]) {
            return null;
        }
        ++$i;
        $depth = 1;
        $atKey = true;
        $count = 0;
        while ($i < $length) {
            $char = $json[$i];
            if ('"' === $char) {
                if (1 === $depth && $atKey) {
                    ++$count;
                    $atKey = false;
                }
                $i = $this->skipString($json, $i, $length);

                continue;
            }
            if ('{' === $char || '[' === $char) {
                ++$depth;
            } elseif ('}' === $char || ']' === $char) {
                --$depth;
                if (0 === $depth) {
                    return $count;
                }
            } elseif (',' === $char && 1 === $depth) {
                $atKey = true;
            }
            ++$i;
        }

        return null;
    }

    /**
     * Advance past a JSON string starting at the opening quote, honouring
     * backslash escapes, and return the index just after the closing quote.
     */
    private function skipString(string $json, int $index, int $length): int
    {
        $i = $index + 1;
        while ($i < $length) {
            $char = $json[$i];
            if ('\\' === $char) {
                $i += 2;

                continue;
            }
            if ('"' === $char) {
                return $i + 1;
            }
            ++$i;
        }

        return $length;
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
