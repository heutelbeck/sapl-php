<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

use Sapl\Api\AuthorizationDecision;

/**
 * Entered or resumed the permitting state (initial grant or resume from
 * suspended). Plan replacement while already permitting is silent.
 */
final class Granted implements TransitionReason
{
    public function __construct(
        public readonly AuthorizationDecision $decision,
    ) {
    }
}
