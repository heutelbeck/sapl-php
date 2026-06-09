<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

/**
 * The PDP returned an explicit SUSPEND. The subscription is preserved and RAP
 * items are dropped silently; the next PERMIT resumes to {@see Permitting}, any
 * denial terminates.
 */
final class Suspended implements State
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
