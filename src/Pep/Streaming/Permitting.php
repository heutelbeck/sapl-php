<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

use Sapl\Pep\Constraints\EnforcementPlan;

/**
 * The current decision permits and the plan is usable. Per-item enforcement and
 * lifecycle signals run against this plan while it is current.
 */
final class Permitting implements State
{
    public function __construct(
        public readonly EnforcementPlan $plan,
    ) {
    }
}
