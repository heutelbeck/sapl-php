<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

use Sapl\Api\AuthorizationDecision;
use Sapl\Pep\Constraints\EnforcementPlan;

/**
 * PERMIT, and decision-scoped enforcement succeeded.
 */
final class PdpPermit implements Event
{
    public function __construct(
        public readonly AuthorizationDecision $decision,
        public readonly EnforcementPlan $plan,
    ) {
    }
}
