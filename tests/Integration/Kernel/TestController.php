<?php

declare(strict_types=1);

namespace Sapl\Tests\Integration\Kernel;

use Sapl\Symfony\PostEnforce;
use Sapl\Symfony\PreEnforce;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller used by the bundle kernel test to exercise the enforcement
 * subscriber end to end.
 */
final class TestController
{
    #[PreEnforce(action: 'read', resource: 'doc')]
    public function pre(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }

    #[PostEnforce(action: 'read', resource: 'doc')]
    public function post(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
