<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Pep\Constraints;

use PHPUnit\Framework\TestCase;
use Sapl\Pep\Constraints\ActivePlan;
use Sapl\Pep\Constraints\EnforcementPlan;

final class ActivePlanTest extends TestCase
{
    protected function tearDown(): void
    {
        ActivePlan::reset();
    }

    public function testGetIsNullByDefault(): void
    {
        self::assertNull(ActivePlan::get());
    }

    public function testSetMakesPlanVisible(): void
    {
        $plan = EnforcementPlan::empty();
        ActivePlan::set($plan);

        self::assertSame($plan, ActivePlan::get());
    }

    public function testResetClearsThePlan(): void
    {
        ActivePlan::set(EnforcementPlan::empty());
        ActivePlan::reset();

        self::assertNull(ActivePlan::get());
    }
}
