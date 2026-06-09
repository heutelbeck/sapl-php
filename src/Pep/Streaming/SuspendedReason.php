<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

use Sapl\Api\AuthorizationDecision;

/**
 * Suspended by an explicit SUSPEND from the PDP.
 *
 * Named SuspendedReason (not Suspended) to avoid colliding with the
 * {@see Suspended} state in the same namespace.
 */
final class SuspendedReason implements TransitionReason
{
    public function __construct(
        public readonly AuthorizationDecision $decision,
    ) {
    }
}
