<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Pep\Constraints\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sapl\Pep\AccessDeniedException;
use Sapl\Pep\Constraints\Providers\ContentFilter;
use stdClass;

/**
 * Content filtering must fail closed: when a `filterJsonContent` redaction cannot
 * be applied exactly as written, the protected payload is denied rather than
 * released unredacted. Mirrors the Spring reference (ContentFilter.java R21/R24/R25).
 */
final class ContentFilterTest extends TestCase
{
    private const string SSN = '123-45-6789';

    /**
     * The protected payload is never released when the redaction cannot be applied.
     *
     * @param list<mixed> $actions
     */
    #[DataProvider('unenforceableRedactionCases')]
    public function testDeniesWhenRedactionCannotBeApplied(array $actions, mixed $value): void
    {
        $this->expectException(AccessDeniedException::class);

        ContentFilter::apply($actions, $value);
    }

    /**
     * @return iterable<string, array{list<mixed>, mixed}>
     */
    public static function unenforceableRedactionCases(): iterable
    {
        yield 'path absent from data' => [
            [['type' => 'blacken', 'path' => '$.ssn']],
            ['name' => 'Alice'],
        ];
        yield 'bracket json path unsupported' => [
            [['type' => 'blacken', 'path' => "\$['ssn']"]],
            ['ssn' => self::SSN, 'name' => 'Alice'],
        ];
        yield 'recursive descent path unsupported' => [
            [['type' => 'blacken', 'path' => '$..ssn']],
            ['ssn' => self::SSN, 'name' => 'Alice'],
        ];
        yield 'unknown action type' => [
            [['type' => 'remove', 'path' => '$.ssn']],
            ['ssn' => self::SSN, 'name' => 'Alice'],
        ];
        yield 'action is not an object' => [
            ['blacken $.ssn'],
            ['ssn' => self::SSN, 'name' => 'Alice'],
        ];
        yield 'blacken target is not textual' => [
            [['type' => 'blacken', 'path' => '$.ssn']],
            ['ssn' => 123456789, 'name' => 'Alice'],
        ];
    }

    /**
     * A successful redaction on a plain associative array still works.
     */
    public function testBlackensTextualLeafOnArrayPayload(): void
    {
        $result = ContentFilter::apply(
            [['type' => 'blacken', 'path' => '$.ssn', 'discloseRight' => 4]],
            ['ssn' => self::SSN, 'name' => 'Alice'],
        );

        self::assertSame(['ssn' => str_repeat("\u{2588}", 7).'6789', 'name' => 'Alice'], $result);
    }

    /**
     * DTO and object payloads are filtered too; the sensitive field must not survive.
     */
    public function testDoesNotLeakSensitiveFieldOfObjectPayload(): void
    {
        $user = new stdClass();
        $user->ssn = self::SSN;
        $user->name = 'Alice';

        $result = ContentFilter::apply([['type' => 'blacken', 'path' => '$.ssn']], $user);

        self::assertStringNotContainsString(self::SSN, (string) json_encode($result));
    }

    /**
     * Output amplification is capped: a long replacement repeated over a long field
     * exceeds the permitted blacken length and denies.
     */
    public function testDeniesWhenBlackenOutputExceedsMaximumLength(): void
    {
        $longReplacement = str_repeat('x', 1000);
        $longTarget = str_repeat('a', 1001);

        $this->expectException(AccessDeniedException::class);

        ContentFilter::apply(
            [['type' => 'blacken', 'path' => '$.secret', 'replacement' => $longReplacement]],
            ['secret' => $longTarget],
        );
    }

    /**
     * An explicit blacken length controls the repetition count rather than the
     * original value length.
     */
    public function testBlackenLengthControlsRepetitionCount(): void
    {
        $result = ContentFilter::apply(
            [['type' => 'blacken', 'path' => '$.secret', 'replacement' => 'X', 'length' => 3]],
            ['secret' => 'abcdefghij'],
        );

        self::assertSame(['secret' => 'XXX'], $result);
    }

    /**
     * With no replacement specified the default redaction glyph is the full block,
     * not the ASCII letter 'X'.
     */
    public function testDefaultReplacementIsFullBlockGlyph(): void
    {
        $result = ContentFilter::apply(
            [['type' => 'blacken', 'path' => '$.secret']],
            ['secret' => 'abcde'],
        );

        self::assertSame(['secret' => str_repeat("\u{2588}", 5)], $result);
    }
}
