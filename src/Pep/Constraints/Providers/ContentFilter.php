<?php

declare(strict_types=1);

namespace Sapl\Pep\Constraints\Providers;

/**
 * Applies `filterJsonContent` actions (blacken / replace / delete) to a decoded
 * JSON value.
 *
 * A list value has each element filtered. Path syntax is simple dot-notation only
 * (`$.field.nested`); array indexing, wildcards, and recursive descent are not
 * supported, and segments matching prototype-pollution keys are rejected. PHP
 * arrays are value types, so filtering returns a modified copy.
 */
final class ContentFilter
{
    private const array FORBIDDEN_KEYS = ['__proto__', '__class__', '__dict__', '__globals__', '__builtins__', '__subclasses__'];

    private function __construct()
    {
    }

    /**
     * @param list<mixed> $actions
     */
    public static function apply(array $actions, mixed $value): mixed
    {
        if (is_array($value) && array_is_list($value)) {
            return array_map(static fn (mixed $element): mixed => self::filterSingle($actions, $element), $value);
        }

        return self::filterSingle($actions, $value);
    }

    /**
     * @param list<mixed> $actions
     */
    private static function filterSingle(array $actions, mixed $value): mixed
    {
        foreach ($actions as $action) {
            $value = self::applyAction($value, $action);
        }

        return $value;
    }

    private static function applyAction(mixed $value, mixed $action): mixed
    {
        if (!is_array($value) || !is_array($action) || !is_string($action['type'] ?? null)) {
            return $value;
        }
        $segments = self::parsePath($action['path'] ?? null);
        if (null === $segments) {
            return $value;
        }

        return match ($action['type']) {
            'blacken' => self::modifyAtPath($value, $segments, self::blackenTransform($action)),
            'replace' => self::modifyAtPath($value, $segments, static fn (mixed $current): mixed => $action['replacement'] ?? null),
            'delete' => self::modifyAtPath($value, $segments, null),
            default => $value,
        };
    }

    /**
     * @param array<array-key, mixed> $action
     *
     * @return callable(mixed): mixed
     */
    private static function blackenTransform(array $action): callable
    {
        $replacement = is_string($action['replacement'] ?? null) ? $action['replacement'] : 'X';
        $left = is_int($action['discloseLeft'] ?? null) ? $action['discloseLeft'] : 0;
        $right = is_int($action['discloseRight'] ?? null) ? $action['discloseRight'] : 0;

        return static fn (mixed $current): mixed => is_string($current)
            ? self::blacken($current, $replacement, $left, $right)
            : $current;
    }

    /**
     * @param array<array-key, mixed>       $value
     * @param list<string>                  $segments
     * @param (callable(mixed): mixed)|null $transform null deletes the leaf
     *
     * @return array<array-key, mixed>
     */
    private static function modifyAtPath(array $value, array $segments, ?callable $transform): array
    {
        $key = $segments[0];
        $rest = array_slice($segments, 1);
        if ([] === $rest) {
            if (null === $transform) {
                unset($value[$key]);
            } elseif (array_key_exists($key, $value)) {
                $value[$key] = $transform($value[$key]);
            }

            return $value;
        }
        if (isset($value[$key]) && is_array($value[$key])) {
            $value[$key] = self::modifyAtPath($value[$key], $rest, $transform);
        }

        return $value;
    }

    /**
     * @return list<string>|null
     */
    private static function parsePath(mixed $path): ?array
    {
        if (!is_string($path)
            || 1 === preg_match('/[\[\]*]|\.\./', $path)
            || 1 !== preg_match('/^\$(\.[a-zA-Z_][a-zA-Z0-9_]*)+$/', $path)) {
            return null;
        }
        $segments = array_slice(explode('.', $path), 1);
        foreach ($segments as $segment) {
            if (in_array($segment, self::FORBIDDEN_KEYS, true)) {
                return null;
            }
        }

        return $segments;
    }

    private static function blacken(string $value, string $replacement, int $discloseLeft, int $discloseRight): string
    {
        $length = strlen($value);
        if (0 === $length || $discloseLeft + $discloseRight >= $length) {
            return $value;
        }
        $left = substr($value, 0, $discloseLeft);
        $right = $discloseRight > 0 ? substr($value, $length - $discloseRight) : '';
        $middle = str_repeat($replacement, $length - $discloseLeft - $discloseRight);

        return $left.$middle.$right;
    }
}
