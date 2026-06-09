<?php

declare(strict_types=1);

namespace Sapl\Tests\Fake;

use Sapl\Pep\Constraints\ConstraintGuards;
use Sapl\Pep\Constraints\ConstraintHandlerProvider;
use Sapl\Pep\Constraints\ScopedHandler;

/**
 * Claims constraints whose "type" equals a configured value and returns a fixed
 * list of scoped handlers for them; returns an empty list otherwise.
 */
final class FakeConstraintHandlerProvider implements ConstraintHandlerProvider
{
    /**
     * @param list<ScopedHandler> $handlers
     */
    public function __construct(
        private readonly string $type,
        private readonly array $handlers,
    ) {
    }

    public function getConstraintHandlers(mixed $constraint, array $supportedSignals): array
    {
        return ConstraintGuards::isOfType($constraint, $this->type) ? $this->handlers : [];
    }
}
