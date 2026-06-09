<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

/**
 * No PDP decision has arrived yet; the pipeline is subscribed to the PDP.
 */
final class Pending implements State
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
