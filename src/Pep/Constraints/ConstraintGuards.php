<?php

declare(strict_types=1);

namespace Sapl\Pep\Constraints;

/**
 * Dispatch helpers for constraint handler providers. (Spring/.NET place these as
 * static methods on the provider interface; PHP interfaces cannot carry method
 * bodies, so they live here.).
 */
final class ConstraintGuards
{
    private function __construct()
    {
    }

    /**
     * True when the constraint is an object whose "type" field equals the expected type.
     */
    public static function isOfType(mixed $constraint, string $expectedType): bool
    {
        return is_array($constraint)
            && isset($constraint['type'])
            && is_string($constraint['type'])
            && $constraint['type'] === $expectedType;
    }

    /**
     * The string value of a named field, or null when absent or not a string.
     */
    public static function stringField(mixed $constraint, string $field): ?string
    {
        if (!is_array($constraint) || !isset($constraint[$field]) || !is_string($constraint[$field])) {
            return null;
        }

        return $constraint[$field];
    }

    /**
     * The list value of a named field, or null when absent or not a list.
     *
     * @return list<mixed>|null
     */
    public static function listField(mixed $constraint, string $field): ?array
    {
        if (!is_array($constraint) || !isset($constraint[$field]) || !is_array($constraint[$field])) {
            return null;
        }

        return array_values($constraint[$field]);
    }

    /**
     * True when the given signal is in the supported set.
     *
     * @param list<SignalKind> $supportedSignals
     */
    public static function supports(array $supportedSignals, SignalKind $signal): bool
    {
        return in_array($signal, $supportedSignals, true);
    }
}
