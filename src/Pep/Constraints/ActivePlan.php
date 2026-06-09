<?php

declare(strict_types=1);

namespace Sapl\Pep\Constraints;

/**
 * Process-global holder for the enforcement plan in scope during a protected
 * method invocation.
 *
 * A Doctrine filter is instantiated by the EntityManager with no access to the
 * dependency-injection container, so it cannot be handed the plan directly. The
 * blocking PEP sets the plan here for the duration of the PreEnforce proceed
 * window and resets it afterwards; the filter reads it when its query fires.
 *
 * This is the PHP analogue of the Spring ThreadLocal, the Python ContextVar, and
 * the NestJS CLS holder. It is safe for synchronous PHP-FPM workers, where each
 * worker handles one request at a time and the PEP resets in a finally block.
 */
final class ActivePlan
{
    private static ?EnforcementPlan $plan = null;

    private function __construct()
    {
    }

    public static function set(EnforcementPlan $plan): void
    {
        self::$plan = $plan;
    }

    public static function get(): ?EnforcementPlan
    {
        return self::$plan;
    }

    public static function reset(): void
    {
        self::$plan = null;
    }
}
