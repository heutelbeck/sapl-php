<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

/**
 * The output alphabet of the streaming PEP's Mealy machine: what the machine asks
 * the downstream adapter to deliver on one transition ({@see Emit},
 * {@see EmitError}, {@see EmitComplete}, {@see EmitTransition}). A step may
 * produce zero, one, or several emissions.
 */
interface Emission
{
}
