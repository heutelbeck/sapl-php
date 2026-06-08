<?php

declare(strict_types=1);

namespace Sapl\Api;

/**
 * A collection of named authorization decisions from a multi-decision request.
 */
final class MultiAuthorizationDecision
{
    /**
     * @param array<string, AuthorizationDecision> $decisions
     */
    public function __construct(
        public readonly array $decisions = [],
    ) {
    }
}
