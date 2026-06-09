<?php

declare(strict_types=1);

namespace Sapl\Symfony;

use Sapl\Pep\Constraints\ShimSignalRegistry;
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

    /**
     * The PreEnforce supported set: the static base unioned with the signals of
     * any registered data-layer shim. PostEnforce and streaming are not shim cut
     * points, so their sets stay fixed and a shim signal never enters them.
     *
     * @return list<SignalKind>
     */
    public static function pre(): array
    {
        return [...self::PRE, ...ShimSignalRegistry::shimSignals()];
    }
}
