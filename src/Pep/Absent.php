<?php

declare(strict_types=1);

namespace Sapl\Pep;

/**
 * No value is present.
 */
final class Absent implements Maybe
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
