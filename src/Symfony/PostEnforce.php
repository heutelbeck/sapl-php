<?php

declare(strict_types=1);

namespace Sapl\Symfony;

use Attribute;

/**
 * Enforce a SAPL policy after the annotated method has produced its result. The
 * result is part of the authorization request, so the decision is obtained after
 * the method returns; a non-PERMIT decision denies and obligations may transform
 * the result.
 *
 * A field may be a literal value or a
 * {@see \Symfony\Component\ExpressionLanguage\Expression}, evaluated against
 * `{ subject, args, request, returnValue }` (the method's result is exposed as
 * `returnValue`).
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class PostEnforce
{
    public function __construct(
        public readonly mixed $subject = null,
        public readonly mixed $action = null,
        public readonly mixed $resource = null,
        public readonly mixed $environment = null,
        public readonly mixed $secrets = null,
    ) {
    }
}
