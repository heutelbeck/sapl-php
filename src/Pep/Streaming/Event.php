<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

/**
 * The input alphabet of the streaming PEP's Mealy machine: PDP-side decision
 * events, RAP-side stream events, and downstream subscriber lifecycle events. The
 * pipeline pre-classifies raw PDP decisions into the verb-specific cases.
 */
interface Event
{
}
