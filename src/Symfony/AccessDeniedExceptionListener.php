<?php

declare(strict_types=1);

namespace Sapl\Symfony;

use Sapl\Pep\AccessDeniedException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Maps a SAPL {@see AccessDeniedException} to an HTTP 403 response, so a denied
 * decision or a failed obligation surfaces as Forbidden without coupling the
 * framework-agnostic engine to HttpKernel.
 */
final class AccessDeniedExceptionListener implements EventSubscriberInterface
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 0]];
    }

    public function onException(ExceptionEvent $event): void
    {
        if ($event->getThrowable() instanceof AccessDeniedException) {
            $event->setResponse(new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN));
        }
    }
}
