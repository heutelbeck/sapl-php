<?php

declare(strict_types=1);

namespace Sapl\Pep\Constraints;

/**
 * Process-global registry of signal kinds advertised by data-layer shims.
 *
 * A shim registers its signal at bundle load. The PreEnforce supported set the
 * PEP passes to the planner is the static base unioned with this registry, so a
 * query-rewriting obligation is admitted only when its shim is actually
 * installed; otherwise the planner rejects it as inadmissible and fails closed.
 *
 * PHP analogue of the Spring ShimSignalContributor, the Python
 * register_shim_signal registry, and the NestJS registerShimSignal.
 */
final class ShimSignalRegistry
{
    /** @var array<string, SignalKind> */
    private static array $signals = [];

    private function __construct()
    {
    }

    /**
     * Advertise a shim signal kind. Idempotent.
     */
    public static function register(SignalKind $signal): void
    {
        self::$signals[$signal->value] = $signal;
    }

    /**
     * Withdraw a previously registered shim signal kind. Idempotent.
     */
    public static function unregister(SignalKind $signal): void
    {
        unset(self::$signals[$signal->value]);
    }

    /**
     * A snapshot of the currently registered shim signal kinds.
     *
     * @return list<SignalKind>
     */
    public static function shimSignals(): array
    {
        return array_values(self::$signals);
    }
}
