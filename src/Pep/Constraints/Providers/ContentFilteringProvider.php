<?php

declare(strict_types=1);

namespace Sapl\Pep\Constraints\Providers;

use Sapl\Pep\Constraints\ConstraintGuards;
use Sapl\Pep\Constraints\ConstraintHandlerProvider;
use Sapl\Pep\Constraints\Mapper;
use Sapl\Pep\Constraints\ScopedHandler;
use Sapl\Pep\Constraints\SignalKind;

/**
 * Built-in provider for `filterJsonContent` obligations. Schedules an output
 * mapper at priority 0 that applies the configured blacken / replace / delete
 * actions to the method's result via {@see ContentFilter}. An optional
 * `conditions` predicate gates the actions per element: matching elements are
 * transformed, non-matching elements pass through unchanged.
 */
final class ContentFilteringProvider implements ConstraintHandlerProvider
{
    private const string CONSTRAINT_TYPE = 'filterJsonContent';
    private const int PRIORITY = 0;

    public function getConstraintHandlers(mixed $constraint, array $supportedSignals): array
    {
        if (!ConstraintGuards::isOfType($constraint, self::CONSTRAINT_TYPE) || !is_array($constraint)) {
            return [];
        }
        $actionsRaw = $constraint['actions'] ?? [];
        if (!is_array($actionsRaw)) {
            return [];
        }
        $actions = array_values($actionsRaw);
        $conditions = $constraint['conditions'] ?? [];

        return [
            new ScopedHandler(
                new Mapper(static fn (mixed $value): mixed => ContentFilter::apply($actions, $value, $conditions)),
                SignalKind::OUTPUT,
                self::PRIORITY,
            ),
        ];
    }
}
