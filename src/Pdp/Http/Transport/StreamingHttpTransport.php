<?php

declare(strict_types=1);

namespace Sapl\Pdp\Http\Transport;

use React\Promise\PromiseInterface;

/**
 * Promise-based transport for streaming PDP subscriptions over ReactPHP.
 *
 * The returned promise resolves once response headers arrive, carrying the
 * status code and a readable body stream. It rejects on a connection-level
 * failure; the client's reconnect loop turns either outcome into a backoff.
 */
interface StreamingHttpTransport
{
    /**
     * @param array<string, string> $headers
     *
     * @return PromiseInterface<StreamingResponse>
     */
    public function open(string $method, string $url, array $headers, string $body): PromiseInterface;
}
