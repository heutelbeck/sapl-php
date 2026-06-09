<?php

declare(strict_types=1);

namespace Sapl\Symfony;

use Sapl\Api\AuthorizationSubscription;

/**
 * Builds an {@see AuthorizationSubscription} from an enforcement attribute and the
 * method context.
 *
 * The subject defaults to the resolved current subject, the action to
 * "ShortClass.method", and the resource to the short class name (or, for a
 * post-enforce result, the returned value). Any field set on the attribute wins.
 */
final class AuthorizationSubscriptionBuilder
{
    public function __construct(
        private readonly SubjectResolver $subjectResolver,
    ) {
    }

    public function forInvocation(
        PreEnforce|StreamEnforce $attribute,
        string $class,
        string $method,
    ): AuthorizationSubscription {
        return new AuthorizationSubscription(
            subject: $attribute->subject ?? $this->subjectResolver->currentSubject(),
            action: $attribute->action ?? $this->shortName($class).'.'.$method,
            resource: $attribute->resource ?? $this->shortName($class),
            environment: $attribute->environment,
        );
    }

    public function forResult(
        PostEnforce $attribute,
        string $class,
        string $method,
        mixed $returnValue,
    ): AuthorizationSubscription {
        return new AuthorizationSubscription(
            subject: $attribute->subject ?? $this->subjectResolver->currentSubject(),
            action: $attribute->action ?? $this->shortName($class).'.'.$method,
            resource: $attribute->resource ?? $returnValue,
            environment: $attribute->environment,
        );
    }

    private function shortName(string $class): string
    {
        $tail = strrchr($class, '\\');

        return false === $tail ? $class : substr($tail, 1);
    }
}
