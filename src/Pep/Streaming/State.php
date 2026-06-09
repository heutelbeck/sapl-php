<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

/**
 * The state set of the streaming PEP's Mealy machine. Four cases describe the
 * whole lifecycle of one subscription ({@see Pending}, {@see Permitting},
 * {@see Suspended}, {@see Terminated}). Routing is driven by the PDP decision
 * verb carried by the event, not by a flag.
 */
interface State
{
}
