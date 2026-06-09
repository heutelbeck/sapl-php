<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Pep\Constraints;

use PHPUnit\Framework\TestCase;
use Sapl\Pep\Constraints\ShimSignalRegistry;
use Sapl\Pep\Constraints\SignalKind;

final class ShimSignalRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        ShimSignalRegistry::unregister(SignalKind::SQL_QUERY);
    }

    public function testRegistryIsEmptyByDefault(): void
    {
        self::assertSame([], ShimSignalRegistry::shimSignals());
    }

    public function testRegisteredSignalAppearsInSnapshot(): void
    {
        ShimSignalRegistry::register(SignalKind::SQL_QUERY);

        self::assertSame([SignalKind::SQL_QUERY], ShimSignalRegistry::shimSignals());
    }

    public function testRegisterIsIdempotent(): void
    {
        ShimSignalRegistry::register(SignalKind::SQL_QUERY);
        ShimSignalRegistry::register(SignalKind::SQL_QUERY);

        self::assertSame([SignalKind::SQL_QUERY], ShimSignalRegistry::shimSignals());
    }

    public function testUnregisterRemovesSignalAndIsIdempotent(): void
    {
        ShimSignalRegistry::register(SignalKind::SQL_QUERY);
        ShimSignalRegistry::unregister(SignalKind::SQL_QUERY);
        ShimSignalRegistry::unregister(SignalKind::SQL_QUERY);

        self::assertSame([], ShimSignalRegistry::shimSignals());
    }
}
