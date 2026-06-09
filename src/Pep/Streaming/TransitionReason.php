<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

/**
 * Why the machine crossed a state boundary. Carried by {@see EmitTransition} so
 * subscribers can react ({@see Granted}, {@see Suspended}).
 */
interface TransitionReason
{
}
