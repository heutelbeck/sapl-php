<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sapl\Pdp\Reconnect\BackoffPolicy;

final class BackoffPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{int, float, float}>
     */
    public static function delays(): iterable
    {
        // base 1.0, cap 30.0, randomizer fixed at 0.0 so jitter factor is 0.5.
        yield 'attempt 1 -> base' => [1, 0.5, 0.0];
        yield 'attempt 2 -> doubled' => [2, 1.0, 0.0];
        yield 'attempt 3 -> quadrupled' => [3, 2.0, 0.0];
        yield 'attempt 10 -> capped' => [10, 15.0, 0.0];
        yield 'attempt 1 full jitter' => [1, 1.0, 1.0];
        yield 'attempt 10 capped full jitter' => [10, 30.0, 1.0];
    }

    #[DataProvider('delays')]
    public function testDelayIsExponentialCappedAndJittered(int $attempt, float $expected, float $random): void
    {
        $policy = new BackoffPolicy(1.0, 30.0, static fn (): float => $random);

        self::assertEqualsWithDelta($expected, $policy->delayForAttempt($attempt), 0.0001);
    }

    public function testLogLevelEscalatesAtThreshold(): void
    {
        $policy = new BackoffPolicy();

        self::assertSame('warning', $policy->logLevelForAttempt(1));
        self::assertSame('warning', $policy->logLevelForAttempt(4));
        self::assertSame('error', $policy->logLevelForAttempt(5));
        self::assertSame('error', $policy->logLevelForAttempt(99));
    }
}
