<?php

declare(strict_types=1);

namespace Sapl\Symfony;

use Sapl\Api\AuthorizationSubscription;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds an {@see AuthorizationSubscription} from an enforcement attribute and the
 * method context.
 *
 * Each attribute field may be a literal value or a {@see Expression}, evaluated
 * against `{ subject, args, request }` (plus `returnValue` for a post-enforce
 * result). An unset field falls back to a default: the subject to the resolved
 * current subject; the action to `{ method, controller, handler }`; the resource
 * to `{ path, params }` derived from the current request. The returned value of a
 * post-enforce method is not the default resource; it is reachable through the
 * `returnValue` expression variable.
 */
final class AuthorizationSubscriptionBuilder
{
    public function __construct(
        private readonly SubjectResolver $subjectResolver,
        private readonly ExpressionLanguage $expressionLanguage,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    /**
     * @param array<string, mixed> $args
     */
    public function forInvocation(
        PreEnforce|StreamEnforce $attribute,
        string $class,
        string $method,
        array $args = [],
    ): AuthorizationSubscription {
        return $this->build(
            $attribute,
            $this->context($args),
            $this->defaultAction($class, $method),
            $this->defaultResource(),
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    public function forResult(
        PostEnforce $attribute,
        string $class,
        string $method,
        mixed $returnValue,
        array $args = [],
    ): AuthorizationSubscription {
        $context = $this->context($args);
        $context['returnValue'] = $returnValue;

        return $this->build($attribute, $context, $this->defaultAction($class, $method), $this->defaultResource());
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $actionDefault
     * @param array<string, mixed> $resourceDefault
     */
    private function build(
        PreEnforce|PostEnforce|StreamEnforce $attribute,
        array $context,
        array $actionDefault,
        array $resourceDefault,
    ): AuthorizationSubscription {
        return new AuthorizationSubscription(
            subject: null !== $attribute->subject
                ? $this->evaluate($attribute->subject, $context)
                : $this->subjectResolver->currentSubject(),
            action: null !== $attribute->action ? $this->evaluate($attribute->action, $context) : $actionDefault,
            resource: null !== $attribute->resource ? $this->evaluate($attribute->resource, $context) : $resourceDefault,
            environment: null !== $attribute->environment ? $this->evaluate($attribute->environment, $context) : null,
            secrets: null !== $attribute->secrets ? $this->evaluate($attribute->secrets, $context) : null,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function evaluate(mixed $field, array $context): mixed
    {
        return $field instanceof Expression
            ? $this->expressionLanguage->evaluate($field, $context)
            : $field;
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function context(array $args): array
    {
        return [
            'subject' => $this->subjectResolver->currentSubject(),
            'args' => $args,
            'request' => $this->requestStack?->getCurrentRequest(),
        ];
    }

    /**
     * The default action: the HTTP method (when called within a request) plus the
     * controller and handler coordinates.
     *
     * @return array<string, mixed>
     */
    private function defaultAction(string $class, string $method): array
    {
        $action = [];
        $request = $this->requestStack?->getCurrentRequest();
        if (null !== $request) {
            $action['method'] = $request->getMethod();
        }
        $action['controller'] = $this->shortName($class);
        $action['handler'] = $method;

        return $action;
    }

    /**
     * The default resource: the request path and route parameters, or empty values
     * when called outside a request (a console command or message handler).
     *
     * @return array<string, mixed>
     */
    private function defaultResource(): array
    {
        $request = $this->requestStack?->getCurrentRequest();
        if (null === $request) {
            return ['path' => '', 'params' => []];
        }
        $params = $request->attributes->get('_route_params', []);

        return [
            'path' => $request->getPathInfo(),
            'params' => is_array($params) ? $params : [],
        ];
    }

    private function shortName(string $class): string
    {
        $tail = strrchr($class, '\\');

        return false === $tail ? $class : substr($tail, 1);
    }
}
