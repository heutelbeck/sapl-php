<?php

declare(strict_types=1);

namespace Sapl\Pep\Constraints\Providers;

use Sapl\Pep\AccessDeniedException;

/**
 * Applies `filterJsonContent` actions (blacken / replace / delete) to a decoded
 * JSON value.
 *
 * A list value has each element filtered. Path syntax is simple dot-notation only
 * (`$.field.nested`); array indexing, wildcards, and recursive descent are not
 * supported, and segments matching prototype-pollution keys are rejected. PHP
 * arrays are value types, so filtering returns a modified copy.
 *
 * Content filtering fails closed: when a redaction cannot be applied exactly as
 * written (path absent, unsupported path syntax, unknown action, non-textual
 * blacken target, output amplification beyond the permitted length) the call
 * throws {@see AccessDeniedException} so the protected payload is denied rather
 * than released unredacted. Mirrors the Spring reference ContentFilter.java.
 */
final class ContentFilter
{
    private const array FORBIDDEN_KEYS = ['__proto__', '__class__', '__dict__', '__globals__', '__builtins__', '__subclasses__'];

    private const string BLACK_SQUARE = "\u{2588}";

    private const int MAX_BLACKEN = 1_000_000;

    private const string ERROR_ACTION_NOT_AN_OBJECT = 'An action in \'actions\' is not an object.';
    private const string ERROR_CONDITIONS_NOT_AN_ARRAY = '\'conditions\' is not an array.';
    private const string ERROR_CONDITION_INVALID = 'A predicate condition in \'conditions\' is not valid.';
    private const string ERROR_CONDITION_PATH_NOT_PRESENT = 'A predicate condition path is not present in the data.';
    private const string ERROR_CONDITION_PATH_UNSUPPORTED = 'A predicate condition path uses unsupported syntax.';
    private const string ERROR_LENGTH_NOT_NUMBER = '\'length\' of \'blacken\' action is not numeric.';
    private const string ERROR_LENGTH_TOO_LARGE = '\'length\' of \'blacken\' action exceeds the maximum permitted blacken length.';
    private const string ERROR_NO_REPLACEMENT = 'The action does not specify a \'replacement\'.';
    private const string ERROR_PATH_NOT_PRESENT = 'The path defined in the constraint is not present in the data.';
    private const string ERROR_PATH_NOT_TEXTUAL = 'The node identified by the path is not a text node.';
    private const string ERROR_PATH_UNSUPPORTED = 'The constraint path uses unsupported syntax.';
    private const string ERROR_REGEX_UNSAFE = 'Unsafe regex pattern rejected (potential ReDoS).';
    private const string ERROR_REPLACEMENT_NOT_TEXTUAL = '\'replacement\' of \'blacken\' action is not textual.';
    private const string ERROR_UNKNOWN_ACTION = 'Unknown action type.';

    private function __construct()
    {
    }

    /**
     * @param list<mixed> $actions
     * @param mixed       $conditions a conjunctive list of predicate conditions that gates the
     *                                actions; matching elements are transformed, non-matching
     *                                elements pass through unchanged
     */
    public static function apply(array $actions, mixed $value, mixed $conditions = []): mixed
    {
        $predicate = self::predicateFromConditions($conditions);
        if (is_array($value) && array_is_list($value)) {
            return array_map(static fn (mixed $element): mixed => self::mapElement($actions, $predicate, $element), $value);
        }

        return self::mapElement($actions, $predicate, $value);
    }

    /**
     * @param list<mixed>          $actions
     * @param callable(mixed):bool $predicate
     */
    private static function mapElement(array $actions, callable $predicate, mixed $element): mixed
    {
        if ($predicate($element)) {
            return self::filterElement($actions, $element);
        }

        return $element;
    }

    /**
     * @param list<mixed> $actions
     */
    private static function filterElement(array $actions, mixed $value): mixed
    {
        if (is_object($value)) {
            return self::arrayToObject(self::filterSingle($actions, self::objectToArray($value)));
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
        if (!is_array($action) || !is_string($action['type'] ?? null) || !is_string($action['path'] ?? null)) {
            throw new AccessDeniedException(self::ERROR_ACTION_NOT_AN_OBJECT);
        }
        $segments = self::parsePath($action['path']);
        if (null === $segments) {
            throw new AccessDeniedException(self::ERROR_PATH_UNSUPPORTED);
        }
        if (!is_array($value) || !self::pathIsPresent($value, $segments)) {
            throw new AccessDeniedException(self::ERROR_PATH_NOT_PRESENT);
        }

        return match (strtolower(trim($action['type']))) {
            'blacken' => self::modifyAtPath($value, $segments, self::blackenTransform($action)),
            'replace' => self::modifyAtPath($value, $segments, self::replaceTransform($action)),
            'delete' => self::modifyAtPath($value, $segments, null),
            default => throw new AccessDeniedException(self::ERROR_UNKNOWN_ACTION),
        };
    }

    /**
     * @param array<array-key, mixed> $action
     *
     * @return callable(mixed): mixed
     */
    private static function blackenTransform(array $action): callable
    {
        $replacement = self::determineReplacement($action);
        $left = is_int($action['discloseLeft'] ?? null) ? $action['discloseLeft'] : 0;
        $right = is_int($action['discloseRight'] ?? null) ? $action['discloseRight'] : 0;
        $blackenLength = self::determineBlackenLength($action);

        return static function (mixed $current) use ($replacement, $left, $right, $blackenLength): string {
            if (!is_string($current)) {
                throw new AccessDeniedException(self::ERROR_PATH_NOT_TEXTUAL);
            }

            return self::blacken($current, $replacement, $left, $right, $blackenLength);
        };
    }

    /**
     * @param array<array-key, mixed> $action
     *
     * @return callable(mixed): mixed
     */
    private static function replaceTransform(array $action): callable
    {
        if (!array_key_exists('replacement', $action) || null === $action['replacement']) {
            throw new AccessDeniedException(self::ERROR_NO_REPLACEMENT);
        }
        $replacement = $action['replacement'];

        return static fn (mixed $current): mixed => $replacement;
    }

    /**
     * @param array<array-key, mixed> $action
     */
    private static function determineReplacement(array $action): string
    {
        $replacement = $action['replacement'] ?? null;
        if (null === $replacement) {
            return self::BLACK_SQUARE;
        }
        if (!is_string($replacement)) {
            throw new AccessDeniedException(self::ERROR_REPLACEMENT_NOT_TEXTUAL);
        }

        return $replacement;
    }

    /**
     * @param array<array-key, mixed> $action
     *
     * @return int the explicit repetition count, or -1 when none is configured
     */
    private static function determineBlackenLength(array $action): int
    {
        if (!array_key_exists('length', $action)) {
            return -1;
        }
        $length = $action['length'];
        if (!is_int($length) || $length < 0) {
            throw new AccessDeniedException(self::ERROR_LENGTH_NOT_NUMBER);
        }
        if ($length > self::MAX_BLACKEN) {
            throw new AccessDeniedException(self::ERROR_LENGTH_TOO_LARGE);
        }

        return $length;
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string>            $segments
     */
    private static function pathIsPresent(array $value, array $segments): bool
    {
        $current = $value;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }
            $current = $current[$segment];
        }

        return true;
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
            } else {
                $value[$key] = $transform($value[$key]);
            }

            return $value;
        }
        if (is_array($value[$key])) {
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

    /**
     * Builds the conjunctive gating predicate from the constraint's `conditions`. An
     * absent (empty) conditions list matches every element. A condition whose path is
     * absent from an element denies the access by throwing.
     *
     * @return callable(mixed): bool
     */
    private static function predicateFromConditions(mixed $conditions): callable
    {
        if (!is_array($conditions)) {
            throw new AccessDeniedException(self::ERROR_CONDITIONS_NOT_AN_ARRAY);
        }
        $predicates = array_map(self::conditionToPredicate(...), array_values($conditions));

        return static function (mixed $element) use ($predicates): bool {
            foreach ($predicates as $predicate) {
                if (!$predicate($element)) {
                    return false;
                }
            }

            return true;
        };
    }

    /**
     * @return callable(mixed): bool
     */
    private static function conditionToPredicate(mixed $condition): callable
    {
        if (!is_array($condition)
            || !is_string($condition['path'] ?? null)
            || !is_string($condition['type'] ?? null)
            || !array_key_exists('value', $condition)
            || null === $condition['value']) {
            throw new AccessDeniedException(self::ERROR_CONDITION_INVALID);
        }
        $segments = self::parsePath($condition['path']);
        if (null === $segments) {
            throw new AccessDeniedException(self::ERROR_CONDITION_PATH_UNSUPPORTED);
        }
        $value = $condition['value'];

        return match (trim($condition['type'])) {
            '==' => self::equalsPredicate($segments, $value),
            '!=' => static fn (mixed $element): bool => !self::equalsPredicate($segments, $value)($element),
            '>=' => self::numericPredicate($segments, $value, static fn (int $c): bool => $c >= 0),
            '<=' => self::numericPredicate($segments, $value, static fn (int $c): bool => $c <= 0),
            '>' => self::numericPredicate($segments, $value, static fn (int $c): bool => $c > 0),
            '<' => self::numericPredicate($segments, $value, static fn (int $c): bool => $c < 0),
            '=~' => self::regexPredicate($segments, $value),
            default => throw new AccessDeniedException(self::ERROR_CONDITION_INVALID),
        };
    }

    /**
     * @param list<string> $segments
     *
     * @return callable(mixed): bool
     */
    private static function equalsPredicate(array $segments, mixed $conditionValue): callable
    {
        if (is_int($conditionValue) || is_float($conditionValue)) {
            return static function (mixed $element) use ($segments, $conditionValue): bool {
                $value = self::valueAtPath($element, $segments);

                return (is_int($value) || is_float($value)) && 0 === ($value <=> $conditionValue);
            };
        }
        if (is_string($conditionValue)) {
            return static function (mixed $element) use ($segments, $conditionValue): bool {
                $value = self::valueAtPath($element, $segments);

                return is_string($value) && $conditionValue === $value;
            };
        }
        throw new AccessDeniedException(self::ERROR_CONDITION_INVALID);
    }

    /**
     * @param list<string>       $segments
     * @param callable(int):bool $compare  maps the spaceship result of payload vs condition value
     *
     * @return callable(mixed): bool
     */
    private static function numericPredicate(array $segments, mixed $conditionValue, callable $compare): callable
    {
        if (!is_int($conditionValue) && !is_float($conditionValue)) {
            throw new AccessDeniedException(self::ERROR_CONDITION_INVALID);
        }

        return static function (mixed $element) use ($segments, $conditionValue, $compare): bool {
            $value = self::valueAtPath($element, $segments);
            if (!is_int($value) && !is_float($value)) {
                return false;
            }

            return $compare($value <=> $conditionValue);
        };
    }

    /**
     * @param list<string> $segments
     *
     * @return callable(mixed): bool
     */
    private static function regexPredicate(array $segments, mixed $conditionValue): callable
    {
        if (!is_string($conditionValue)) {
            throw new AccessDeniedException(self::ERROR_CONDITION_INVALID);
        }
        $pattern = self::compileRegex($conditionValue);

        return static function (mixed $element) use ($segments, $pattern): bool {
            $value = self::valueAtPath($element, $segments);
            if (!is_string($value)) {
                return false;
            }
            // A runaway pattern or hostile input exhausts PCRE's backtrack budget and
            // returns false, which we deny rather than letting it hang the request.
            $matched = @preg_match($pattern, $value);
            if (false === $matched) {
                throw new AccessDeniedException(self::ERROR_REGEX_UNSAFE);
            }

            return 1 === $matched;
        };
    }

    private static function compileRegex(string $pattern): string
    {
        // Anchor the whole input like Java's Matcher#matches so '=~' requires a full match.
        foreach (['/', '#', '~', '%', '@', '!', ';', ','] as $delimiter) {
            if (!str_contains($pattern, $delimiter)) {
                return $delimiter.'\A(?:'.$pattern.')\z'.$delimiter;
            }
        }
        throw new AccessDeniedException(self::ERROR_REGEX_UNSAFE);
    }

    /**
     * @param list<string> $segments
     */
    private static function valueAtPath(mixed $element, array $segments): mixed
    {
        $current = is_object($element) ? self::objectToArray($element) : $element;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                throw new AccessDeniedException(self::ERROR_CONDITION_PATH_NOT_PRESENT);
            }
            $current = $current[$segment];
        }

        return $current;
    }

    private static function blacken(string $value, string $replacement, int $discloseLeft, int $discloseRight, int $blackenLength): string
    {
        $length = strlen($value);
        if (0 === $length || $discloseLeft + $discloseRight >= $length) {
            return $value;
        }
        $replacedChars = $length - $discloseLeft - $discloseRight;
        $repetitions = $blackenLength >= 0 ? $blackenLength : $replacedChars;
        // Bound total output (replacement length x repetitions) to prevent amplification.
        if (strlen($replacement) * $repetitions > self::MAX_BLACKEN) {
            throw new AccessDeniedException(self::ERROR_LENGTH_TOO_LARGE);
        }
        $left = substr($value, 0, $discloseLeft);
        $right = $discloseRight > 0 ? substr($value, $length - $discloseRight) : '';
        $middle = str_repeat($replacement, $repetitions);

        return $left.$middle.$right;
    }

    private static function objectToArray(object $value): mixed
    {
        return json_decode((string) json_encode($value), true);
    }

    private static function arrayToObject(mixed $value): mixed
    {
        return json_decode((string) json_encode($value));
    }
}
