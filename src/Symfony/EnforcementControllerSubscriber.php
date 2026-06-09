<?php

declare(strict_types=1);

namespace Sapl\Symfony;

use Sapl\Pep\BlockingPolicyEnforcementPoint;
use Sapl\Pep\MethodInvocation;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enforces `#[PreEnforce]` / `#[PostEnforce]` / `#[StreamEnforce]` on controller
 * actions.
 *
 * Hooks `kernel.controller_arguments` (the last point at which the resolved
 * controller can be wrapped) and replaces the controller with a wrapper that runs
 * the matching PEP around the original call. A streaming action returns the
 * enforced item stream as the controller result; turning that stream into an HTTP
 * response is the application's concern, not this layer's.
 */
final class EnforcementControllerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly BlockingPolicyEnforcementPoint $pep,
        private readonly AuthorizationSubscriptionBuilder $builder,
        private readonly StreamEnforcer $streamEnforcer,
    ) {
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER_ARGUMENTS => ['onControllerArguments', 10]];
    }

    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        $pre = $event->getAttributes(PreEnforce::class)[0] ?? null;
        $post = $event->getAttributes(PostEnforce::class)[0] ?? null;
        $stream = $event->getAttributes(StreamEnforce::class)[0] ?? null;
        if (null === $pre && null === $post && null === $stream) {
            return;
        }
        [$class, $method] = $this->controllerClassMethod($event->getController());
        $original = $event->getController();
        $namedArguments = $event->getNamedArguments();

        if (null !== $pre) {
            $subscription = $this->builder->forInvocation($pre, $class, $method, $namedArguments);
            $event->setController(
                function (mixed ...$arguments) use ($original, $subscription): mixed {
                    $invocation = new MethodInvocation(
                        array_values($arguments),
                        static fn (array $args): mixed => $original(...$args),
                    );

                    return $this->pep->preEnforce($subscription, EnforcementSignals::pre(), $invocation);
                },
            );

            return;
        }

        if (null !== $stream) {
            $event->setController(
                fn (mixed ...$arguments): mixed => $this->streamEnforcer->enforce(
                    $stream,
                    $class,
                    $method,
                    $namedArguments,
                    $original(...$arguments),
                ),
            );

            return;
        }

        $event->setController(
            function (mixed ...$arguments) use ($original, $post, $class, $method, $namedArguments): mixed {
                $result = $original(...$arguments);
                $subscription = $this->builder->forResult($post, $class, $method, $result, $namedArguments);

                return $this->pep->postEnforce($subscription, EnforcementSignals::POST, $result);
            },
        );
    }

    /**
     * @return array{string, string}
     */
    private function controllerClassMethod(callable $controller): array
    {
        if (is_array($controller)) {
            $target = $controller[0];

            return [is_object($target) ? $target::class : $target, (string) $controller[1]];
        }
        if (is_object($controller)) {
            return [$controller::class, '__invoke'];
        }

        return [is_string($controller) ? $controller : 'closure', '__invoke'];
    }
}
