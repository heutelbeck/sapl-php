<?php

declare(strict_types=1);

namespace Sapl\Tests\Integration\Kernel;

use Psr\Log\NullLogger;
use Sapl\Pdp\PolicyDecisionPoint;
use Sapl\Symfony\SaplBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Minimal kernel that boots FrameworkBundle + SaplBundle with a configurable fake
 * PDP, used to verify the bundle wiring and the enforcement subscriber end to end.
 */
final class SaplTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new SaplBundle()];
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/sapl-kernel-test/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/sapl-kernel-test/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'router' => ['utf8' => true],
        ]);
        $container->extension('sapl', ['pdp' => ['base_url' => 'http://localhost:8443']]);

        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services->set(ConfigurableFakePdp::class)->public();
        $services->alias(PolicyDecisionPoint::class, ConfigurableFakePdp::class);
        $services->set(TokenStorage::class);
        $services->alias(TokenStorageInterface::class, TokenStorage::class);
        $services->set(TestController::class)->public()->autowire()->tag('controller.service_arguments');
        $services->set(TestService::class)->public()->autowire()->autoconfigure();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('pre', '/pre')->controller([TestController::class, 'pre']);
        $routes->add('post', '/post')->controller([TestController::class, 'post']);
    }
}
