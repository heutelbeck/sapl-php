<?php

declare(strict_types=1);

namespace Sapl\Symfony\Proxy;

/**
 * Marks a generated enforcement proxy. The compiler pass replaces an enforced
 * service's class with a generated subclass implementing this interface.
 */
interface SaplProxyMarker
{
}
