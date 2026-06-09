<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

use Sapl\Pep\EnforcementResult;

/**
 * The protected method emitted an item. Per-item enforcement has already been
 * attempted; the result carries the post-mapper value and the failure flag.
 */
final class RapItem implements Event
{
    public function __construct(
        public readonly mixed $payload,
        public readonly EnforcementResult $enforcementResult,
    ) {
    }
}
