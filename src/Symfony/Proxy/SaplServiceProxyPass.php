<?php

declare(strict_types=1);

namespace Sapl\Symfony\Proxy;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Replaces each non-controller service carrying `#[PreEnforce]`/`#[PostEnforce]`
 * on a method (auto-tagged `sapl.enforce`) with a generated enforcement proxy,
 * and injects the blocking PEP and subscription builder via `saplInit`.
 * Controllers are excluded: they are enforced by {@see \Sapl\Symfony\EnforcementControllerSubscriber}.
 */
final class SaplServiceProxyPass implements CompilerPassInterface
{
    public const string TAG = 'sapl.enforce';

    public function process(ContainerBuilder $container): void
    {
        $cacheDir = $container->getParameter('kernel.cache_dir');
        if (!is_string($cacheDir)) {
            return;
        }
        $generator = new SaplProxyGenerator($cacheDir.'/sapl_proxies');

        foreach (array_keys($container->findTaggedServiceIds(self::TAG)) as $id) {
            $definition = $container->getDefinition($id);
            if ($definition->isAbstract()
                || null !== $definition->getFactory()
                || $definition->hasTag('controller.service_arguments')) {
                continue;
            }
            $class = $definition->getClass();
            if (!is_string($class) || !class_exists($class) || !SaplProxyGenerator::hasEnforcedMethod($class)) {
                continue;
            }

            $definition->setClass($generator->generate($class));
            $definition->addMethodCall('__saplInit', [new Reference(SaplInterceptor::class)]);
        }
    }
}
