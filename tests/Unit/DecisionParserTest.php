<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sapl\Api\Decision;
use Sapl\Pdp\Http\DecisionParser;

final class DecisionParserTest extends TestCase
{
    private DecisionParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DecisionParser();
    }

    public function testParsesDecisionWithConstraintsAndResource(): void
    {
        $decision = $this->parser->parseDecision([
            'decision' => 'PERMIT',
            'obligations' => [['type' => 'log']],
            'advice' => [['type' => 'notify']],
            'resource' => ['id' => 7],
        ]);

        self::assertSame(Decision::PERMIT, $decision->decision);
        self::assertSame([['type' => 'log']], $decision->obligations);
        self::assertSame([['type' => 'notify']], $decision->advice);
        self::assertTrue($decision->hasResource);
        self::assertSame(['id' => 7], $decision->resource);
    }

    public function testDecisionWithoutResourceHasNoResourceFlag(): void
    {
        $decision = $this->parser->parseDecision(['decision' => 'DENY']);

        self::assertSame(Decision::DENY, $decision->decision);
        self::assertFalse($decision->hasResource);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformed(): iterable
    {
        yield 'not an array' => ['PERMIT'];
        yield 'missing decision' => [['obligations' => []]];
        yield 'non-string decision' => [['decision' => 42]];
        yield 'unknown verb' => [['decision' => 'MAYBE']];
    }

    #[DataProvider('malformed')]
    public function testFailsClosedToIndeterminate(mixed $raw): void
    {
        self::assertSame(Decision::INDETERMINATE, $this->parser->parseDecision($raw)->decision);
    }

    public function testParseMultiMapsEachEntry(): void
    {
        $multi = $this->parser->parseMulti([
            'a' => ['decision' => 'PERMIT'],
            'b' => ['decision' => 'DENY'],
        ]);

        self::assertNotNull($multi);
        self::assertSame(Decision::PERMIT, $multi->decisions['a']->decision);
        self::assertSame(Decision::DENY, $multi->decisions['b']->decision);
    }

    public function testParseMultiReturnsNullOnNonArray(): void
    {
        self::assertNull($this->parser->parseMulti('nope'));
    }

    public function testParseIdentifiable(): void
    {
        $identifiable = $this->parser->parseIdentifiable([
            'subscriptionId' => 'a',
            'decision' => ['decision' => 'PERMIT'],
        ]);

        self::assertNotNull($identifiable);
        self::assertSame('a', $identifiable->subscriptionId);
        self::assertSame(Decision::PERMIT, $identifiable->decision->decision);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidIdentifiable(): iterable
    {
        yield 'not an array' => ['nope'];
        yield 'missing id' => [['decision' => ['decision' => 'PERMIT']]];
        yield 'empty id' => [['subscriptionId' => '', 'decision' => ['decision' => 'PERMIT']]];
    }

    #[DataProvider('invalidIdentifiable')]
    public function testParseIdentifiableReturnsNullWhenInvalid(mixed $raw): void
    {
        self::assertNull($this->parser->parseIdentifiable($raw));
    }
}
