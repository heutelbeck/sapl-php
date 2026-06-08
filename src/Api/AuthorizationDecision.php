<?php

declare(strict_types=1);

namespace Sapl\Api;

/**
 * A decision received from the PDP.
 *
 * The resource replacement is optional. {@see $hasResource} distinguishes the
 * PDP not providing a replacement (absent) from it providing an explicit null.
 */
final class AuthorizationDecision
{
    /**
     * @param list<mixed> $obligations
     * @param list<mixed> $advice
     */
    public function __construct(
        public readonly Decision $decision = Decision::INDETERMINATE,
        public readonly array $obligations = [],
        public readonly array $advice = [],
        public readonly mixed $resource = null,
        public readonly bool $hasResource = false,
    ) {
    }

    public static function indeterminate(): self
    {
        return new self(Decision::INDETERMINATE);
    }

    public static function permit(): self
    {
        return new self(Decision::PERMIT);
    }

    public static function deny(): self
    {
        return new self(Decision::DENY);
    }

    /**
     * @param list<mixed> $obligations
     * @param list<mixed> $advice
     */
    public static function withResource(
        Decision $decision,
        array $obligations,
        array $advice,
        mixed $resource,
    ): self {
        return new self($decision, $obligations, $advice, $resource, true);
    }
}
