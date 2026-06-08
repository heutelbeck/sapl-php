<?php

declare(strict_types=1);

namespace Sapl\Tests\Fake;

use Sapl\Pdp\Auth\TokenProvider;

/**
 * Token provider that hands out a fixed token and counts invalidations.
 */
final class FakeTokenProvider implements TokenProvider
{
    public int $invalidations = 0;

    public function __construct(
        private readonly string $token = 'test-token',
    ) {
    }

    public function accessToken(): string
    {
        return $this->token;
    }

    public function invalidate(): void
    {
        ++$this->invalidations;
    }
}
