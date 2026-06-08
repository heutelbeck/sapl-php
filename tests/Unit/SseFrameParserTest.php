<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sapl\Pdp\Http\SseBufferOverflowException;
use Sapl\Pdp\Http\SseFrameParser;

final class SseFrameParserTest extends TestCase
{
    public function testParsesCompleteDataFrame(): void
    {
        $parser = new SseFrameParser();

        self::assertSame(
            [['decision' => 'PERMIT']],
            $parser->push("data: {\"decision\":\"PERMIT\"}\n\n"),
        );
    }

    public function testAssemblesFrameSplitAcrossChunks(): void
    {
        $parser = new SseFrameParser();

        self::assertSame([], $parser->push('data: {"decision":'));
        self::assertSame([['decision' => 'DENY']], $parser->push("\"DENY\"}\n"));
    }

    public function testSkipsCommentsBlankLinesAndInvalidJson(): void
    {
        $parser = new SseFrameParser();

        self::assertSame(
            [['decision' => 'PERMIT']],
            $parser->push(": keep-alive comment\n\nnot-json\n\ndata: {\"decision\":\"PERMIT\"}\n"),
        );
    }

    public function testEmitsMultipleFramesFromOneChunk(): void
    {
        $parser = new SseFrameParser();

        self::assertSame(
            [['decision' => 'PERMIT'], ['decision' => 'DENY']],
            $parser->push("data: {\"decision\":\"PERMIT\"}\ndata: {\"decision\":\"DENY\"}\n"),
        );
    }

    public function testThrowsWhenBufferExceedsCap(): void
    {
        $parser = new SseFrameParser(16);
        $oversized = 'data: '.str_repeat('x', 64);

        $this->expectException(SseBufferOverflowException::class);

        $parser->push($oversized);
    }
}
