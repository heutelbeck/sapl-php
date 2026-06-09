<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

/**
 * The downstream subscriber canceled. Terminal.
 */
final class Cancel implements Event
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
