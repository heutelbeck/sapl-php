<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Pep;

use PHPUnit\Framework\TestCase;
use Sapl\Pep\Constraints\Mapper;
use Sapl\Pep\Constraints\Providers\ContentFilter;
use Sapl\Pep\Constraints\Providers\ContentFilteringProvider;
use Sapl\Pep\Constraints\SignalKind;

final class ContentFilterTest extends TestCase
{
    public function testBlackenDisclosesTrailingCharacters(): void
    {
        $action = ['type' => 'blacken', 'path' => '$.ssn', 'replacement' => '*', 'discloseRight' => 4];

        $result = ContentFilter::apply([$action], ['name' => 'Alice', 'ssn' => '123456789']);

        self::assertSame(['name' => 'Alice', 'ssn' => '*****6789'], $result);
    }

    public function testFilterAppliesToEachElementOfAList(): void
    {
        $action = ['type' => 'blacken', 'path' => '$.ssn', 'replacement' => '*', 'discloseRight' => 4];

        $result = ContentFilter::apply([$action], [
            ['ssn' => '123456789'],
            ['ssn' => '987654321'],
        ]);

        self::assertSame([['ssn' => '*****6789'], ['ssn' => '*****4321']], $result);
    }

    public function testReplaceSetsAFixedValue(): void
    {
        $result = ContentFilter::apply(
            [['type' => 'replace', 'path' => '$.password', 'replacement' => '***']],
            ['user' => 'bob', 'password' => 'secret'],
        );

        self::assertSame(['user' => 'bob', 'password' => '***'], $result);
    }

    public function testDeleteRemovesTheKey(): void
    {
        $result = ContentFilter::apply(
            [['type' => 'delete', 'path' => '$.secret']],
            ['id' => 1, 'secret' => 'x'],
        );

        self::assertSame(['id' => 1], $result);
    }

    public function testNestedPath(): void
    {
        $result = ContentFilter::apply(
            [['type' => 'replace', 'path' => '$.profile.email', 'replacement' => 'hidden']],
            ['profile' => ['email' => 'a@b.c', 'name' => 'A']],
        );

        self::assertSame(['profile' => ['email' => 'hidden', 'name' => 'A']], $result);
    }

    public function testUnsupportedPathSyntaxIsIgnored(): void
    {
        $value = ['items' => [['x' => 1]]];

        $result = ContentFilter::apply([['type' => 'delete', 'path' => '$.items[0].x']], $value);

        self::assertSame($value, $result);
    }

    public function testForbiddenKeyIsIgnored(): void
    {
        $value = ['__proto__' => 'keep'];

        $result = ContentFilter::apply([['type' => 'delete', 'path' => '$.__proto__']], $value);

        self::assertSame($value, $result);
    }

    public function testBlackenLeavesNonStringValuesUnchanged(): void
    {
        $value = ['count' => 42];

        $result = ContentFilter::apply([['type' => 'blacken', 'path' => '$.count']], $value);

        self::assertSame($value, $result);
    }

    public function testProviderClaimsFilterJsonContentOnOutput(): void
    {
        $handlers = (new ContentFilteringProvider())->getConstraintHandlers(
            ['type' => 'filterJsonContent', 'actions' => []],
            [SignalKind::OUTPUT],
        );

        self::assertCount(1, $handlers);
        self::assertSame(SignalKind::OUTPUT, $handlers[0]->signal);
        self::assertInstanceOf(Mapper::class, $handlers[0]->handler);
    }

    public function testProviderDeclinesOtherConstraints(): void
    {
        self::assertSame([], (new ContentFilteringProvider())->getConstraintHandlers(['type' => 'logAccess'], [SignalKind::OUTPUT]));
    }
}
