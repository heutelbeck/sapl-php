<?php

declare(strict_types=1);

namespace Sapl\Pdp\Reconnect;

use Closure;

/**
 * Bounded exponential backoff with multiplicative jitter.
 *
 * The delay for attempt N is min(base * 2^(N-1), cap), scaled by a random
 * factor in [0.5, 1.0). The randomizer is injectable so the schedule is
 * deterministic under test.
 */
final class BackoffPolicy
{
    public const float DEFAULT_BASE_SECONDS = 1.0;
    public const float DEFAULT_CAP_SECONDS = 30.0;
    public const int ESCALATION_THRESHOLD = 5;

    /** @var Closure(): float */
    private readonly Closure $randomizer;

    /**
     * @param (Closure(): float)|null $randomizer returns a value in [0.0, 1.0)
     */
    public function __construct(
        private readonly float $baseSeconds = self::DEFAULT_BASE_SECONDS,
        private readonly float $capSeconds = self::DEFAULT_CAP_SECONDS,
        ?Closure $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1);
    }

    /**
     * Delay in seconds before the given 1-based reconnect attempt.
     */
    public function delayForAttempt(int $attempt): float
    {
        $exponent = max(0, $attempt - 1);
        $raw = min($this->baseSeconds * (2 ** $exponent), $this->capSeconds);

        return $raw * (0.5 + ($this->randomizer)() * 0.5);
    }

    /**
     * Log level for a reconnect attempt: WARN below the escalation threshold,
     * ERROR at or above it, so short blips do not spam ERROR.
     */
    public function logLevelForAttempt(int $attempt): string
    {
        return $attempt < self::ESCALATION_THRESHOLD ? 'warning' : 'error';
    }
}
