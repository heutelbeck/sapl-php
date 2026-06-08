<?php

declare(strict_types=1);

namespace Sapl\Pdp\Http\Transport;

/**
 * Blocking transport for one-shot PDP requests.
 *
 * One-shots run on a synchronous HTTP client: a one-off decision in a normal
 * request must not start an event loop. Implementations throw
 * {@see TransportException} on a connection-level failure; an HTTP error status
 * is reported through {@see UnaryResponse::$statusCode}, not thrown.
 */
interface UnaryHttpTransport
{
    /**
     * @param array<string, string> $headers
     *
     * @throws TransportException on a connection-level failure
     */
    public function send(string $method, string $url, array $headers, string $body): UnaryResponse;
}
