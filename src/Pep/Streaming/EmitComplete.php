<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

/**
 * Terminate the subscriber normally.
 */
final class EmitComplete implements Emission
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
