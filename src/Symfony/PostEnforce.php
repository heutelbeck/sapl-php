<?php

declare(strict_types=1);

namespace Sapl\Symfony;

use Attribute;

/**
 * Enforce a SAPL policy after the annotated method has produced its result. The
 * result is part of the authorization request, so the decision is obtained after
 * the method returns; a non-PERMIT decision denies and obligations may transform
 * the result.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class PostEnforce
{
    public function __construct(
        public readonly ?string $subject = null,
        public readonly ?string $action = null,
        public readonly ?string $resource = null,
        public readonly mixed $environment = null,
    ) {
    }
}
