<?php

declare(strict_types=1);

namespace Sapl\Symfony;

use Attribute;

/**
 * Enforce a SAPL policy over a streaming method against a continuous PDP decision
 * stream. The single streaming attribute: a DENY terminates, a SUSPEND pauses
 * (items drop), and the next PERMIT resumes. With {@see $signalTransitions} the
 * suspend/resume boundaries are surfaced to the subscriber.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class StreamEnforce
{
    public function __construct(
        public readonly mixed $subject = null,
        public readonly mixed $action = null,
        public readonly mixed $resource = null,
        public readonly mixed $environment = null,
        public readonly mixed $secrets = null,
        public readonly bool $signalTransitions = false,
        public readonly bool $pauseRapDuringSuspend = false,
    ) {
    }
}
