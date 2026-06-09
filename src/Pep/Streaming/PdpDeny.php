<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

use Sapl\Api\AuthorizationDecision;
use Sapl\Pep\Constraints\EnforcementPlan;

/**
 * Access is denied: an explicit DENY, INDETERMINATE or NOT_APPLICABLE under strict
 * fail-closed, or a PERMIT whose decision-scoped enforcement failed. The
 * {@see DenyKind} discriminates the cause.
 */
final class PdpDeny implements Event
{
    public function __construct(
        public readonly AuthorizationDecision $decision,
        public readonly EnforcementPlan $plan,
        public readonly DenyKind $kind,
    ) {
    }
}
