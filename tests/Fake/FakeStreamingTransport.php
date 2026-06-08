<?php

declare(strict_types=1);

namespace Sapl\Tests\Fake;

use Closure;
use React\Promise\PromiseInterface;

use function React\Promise\reject;

use RuntimeException;
use Sapl\Pdp\Http\Transport\StreamingHttpTransport;

/**
 * Scripted streaming transport. Each open() consumes the next factory, which
 * returns the promise for that connection attempt (resolved with a
 * StreamingResponse, or rejected to simulate a connection failure). Once the
 * factories are exhausted, open() rejects, so a reconnect loop keeps retrying
 * until the consumer closes the stream.
 */
final class FakeStreamingTransport implements StreamingHttpTransport
{
    /** @var list<Closure(): PromiseInterface<\Sapl\Pdp\Http\Transport\StreamingResponse>> */
    private readonly array $factories;

    public int $calls = 0;

    /**
     * @param Closure(): PromiseInterface<\Sapl\Pdp\Http\Transport\StreamingResponse> ...$factories
     */
    public function __construct(Closure ...$factories)
    {
        $this->factories = array_values($factories);
    }

    public function open(string $method, string $url, array $headers, string $body): PromiseInterface
    {
        $index = $this->calls;
        ++$this->calls;
        if ($index >= count($this->factories)) {
            return reject(new RuntimeException('FakeStreamingTransport: scripted connections exhausted'));
        }

        return ($this->factories[$index])();
    }
}
