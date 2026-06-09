<?php

declare(strict_types=1);

namespace Sapl\Symfony;

use Sapl\Pep\Constraints\SignalKind;

/**
 * The signal sets the Symfony PEP fires for each enforcement mode. Shim signals
 * are unioned in at enforcement time when a data-layer shim is registered.
 */
final class EnforcementSignals
{
    /** @var list<SignalKind> */
    public const array PRE = [SignalKind::DECISION, SignalKind::INPUT, SignalKind::OUTPUT, SignalKind::ERROR];

    /** @var list<SignalKind> */
    public const array POST = [SignalKind::DECISION, SignalKind::OUTPUT, SignalKind::ERROR];

    /** @var list<SignalKind> */
    public const array STREAM = [SignalKind::DECISION, SignalKind::OUTPUT, SignalKind::ERROR];

    private function __construct()
    {
    }
}
