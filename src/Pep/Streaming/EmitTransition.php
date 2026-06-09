<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

/**
 * Deliver an out-of-band suspend/resume boundary signal.
 */
final class EmitTransition implements Emission
{
    public function __construct(
        public readonly TransitionReason $reason,
    ) {
    }
}
