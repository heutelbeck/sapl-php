<?php

declare(strict_types=1);

namespace Sapl\Symfony\Proxy;

use ReflectionMethod;
use Sapl\Pep\BlockingPolicyEnforcementPoint;
use Sapl\Pep\MethodInvocation;
use Sapl\Symfony\AuthorizationSubscriptionBuilder;
use Sapl\Symfony\EnforcementSignals;
use Sapl\Symfony\PostEnforce;
use Sapl\Symfony\PreEnforce;
use Sapl\Symfony\StreamEnforce;
use Sapl\Symfony\StreamEnforcer;

/**
 * Runtime for generated service proxies. A proxy's overridden method calls
 * {@see enforce()}, which reads the original method's `#[PreEnforce]` /
 * `#[PostEnforce]` / `#[StreamEnforce]` attribute by reflection and runs the
 * matching PEP around the call. A method without an enforcement attribute passes
 * through.
 */
final class SaplInterceptor
{
    public function __construct(
        private readonly BlockingPolicyEnforcementPoint $pep,
        private readonly AuthorizationSubscriptionBuilder $builder,
        private readonly StreamEnforcer $streamEnforcer,
    ) {
    }

    /**
     * @param class-string                 $class   the original (proxied) class
     * @param list<mixed>                  $args
     * @param callable(list<mixed>): mixed $proceed invokes the original method
     */
    public function enforce(string $class, string $method, array $args, callable $proceed): mixed
    {
        $reflection = new ReflectionMethod($class, $method);

        $pre = $this->attribute($reflection, PreEnforce::class);
        if ($pre instanceof PreEnforce) {
            $subscription = $this->builder->forInvocation($pre, $class, $method, $this->namedArgs($reflection, $args));

            return $this->pep->preEnforce($subscription, EnforcementSignals::pre(), new MethodInvocation($args, $proceed(...)));
        }

        $post = $this->attribute($reflection, PostEnforce::class);
        if ($post instanceof PostEnforce) {
            $result = $proceed($args);
            $subscription = $this->builder->forResult($post, $class, $method, $result, $this->namedArgs($reflection, $args));

            return $this->pep->postEnforce($subscription, EnforcementSignals::POST, $result);
        }

        $stream = $this->attribute($reflection, StreamEnforce::class);
        if ($stream instanceof StreamEnforce) {
            return $this->streamEnforcer->enforce(
                $stream,
                $class,
                $method,
                $this->namedArgs($reflection, $args),
                $proceed($args),
            );
        }

        return $proceed($args);
    }

    private function attribute(ReflectionMethod $reflection, string $attributeClass): ?object
    {
        $attributes = $reflection->getAttributes($attributeClass);

        return [] === $attributes ? null : $attributes[0]->newInstance();
    }

    /**
     * @param list<mixed> $args
     *
     * @return array<string, mixed>
     */
    private function namedArgs(ReflectionMethod $reflection, array $args): array
    {
        $named = [];
        foreach ($reflection->getParameters() as $index => $parameter) {
            if (array_key_exists($index, $args)) {
                $named[$parameter->getName()] = $args[$index];
            }
        }

        return $named;
    }
}
