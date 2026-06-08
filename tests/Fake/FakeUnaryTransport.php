<?php

declare(strict_types=1);

namespace Sapl\Tests\Fake;

use Sapl\Pdp\Http\Transport\TransportException;
use Sapl\Pdp\Http\Transport\UnaryHttpTransport;
use Sapl\Pdp\Http\Transport\UnaryResponse;

/**
 * Scripted unary transport. Each call consumes the next queued outcome (a
 * response to return or an exception to throw); the last outcome repeats once
 * the queue is exhausted.
 */
final class FakeUnaryTransport implements UnaryHttpTransport
{
    /** @var list<UnaryResponse|TransportException> */
    private readonly array $outcomes;

    public int $calls = 0;

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
    public array $received = [];

    public function __construct(UnaryResponse|TransportException ...$outcomes)
    {
        $this->outcomes = array_values($outcomes);
    }

    public function send(string $method, string $url, array $headers, string $body): UnaryResponse
    {
        $this->received[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $index = min($this->calls, count($this->outcomes) - 1);
        ++$this->calls;
        $outcome = $this->outcomes[$index];
        if ($outcome instanceof TransportException) {
            throw $outcome;
        }

        return $outcome;
    }
}
