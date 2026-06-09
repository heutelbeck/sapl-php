<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

use Sapl\Api\AuthorizationDecision;
use Sapl\Pep\Constraints\EnforcementPlan;

/**
 * An explicit SUSPEND from the PDP. The machine derives the boundary reason from
 * the decision.
 */
final class PdpSuspend implements Event
{
    public function __construct(
        public readonly AuthorizationDecision $decision,
        public readonly EnforcementPlan $plan,
    ) {
    }
}
