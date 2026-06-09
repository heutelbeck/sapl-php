<?php

declare(strict_types=1);

namespace Sapl\Symfony;

use Attribute;

/**
 * Enforce a SAPL policy before the annotated controller action or service method
 * runs. A non-PERMIT decision denies; obligations are enforced around the call.
 *
 * The subject defaults to the authenticated user, the action and resource to the
 * class and method name; any field set here overrides the default.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final class PreEnforce
{
    public function __construct(
        public readonly ?string $subject = null,
        public readonly ?string $action = null,
        public readonly ?string $resource = null,
        public readonly mixed $environment = null,
    ) {
    }
}
