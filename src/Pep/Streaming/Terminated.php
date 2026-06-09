<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

/**
 * Absorbing state. Reached on RAP completion or error, downstream cancellation,
 * PDP error, any denial, or a per-item obligation failure. No further events are
 * processed.
 */
final class Terminated implements State
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }
}
