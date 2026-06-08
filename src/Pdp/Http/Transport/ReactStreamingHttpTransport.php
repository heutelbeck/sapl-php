<?php

declare(strict_types=1);

namespace Sapl\Pdp\Http\Transport;

use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;
use React\Socket\Connector;
use React\Stream\ReadableStreamInterface;
use RuntimeException;

/**
 * Streaming transport over the ReactPHP {@see Browser}.
 *
 * The browser is configured to resolve (not reject) on HTTP error responses so
 * the reconnect loop can inspect the status, and with no response timeout so a
 * long-lived SSE subscription is not cut off.
 */
final class ReactStreamingHttpTransport implements StreamingHttpTransport
{
    private readonly Browser $browser;

    public function __construct(
        float $connectTimeoutSeconds = 10.0,
        bool $verifyPeer = true,
    ) {
        $connector = new Connector([
            'tls' => [
                'verify_peer' => $verifyPeer,
                'verify_peer_name' => $verifyPeer,
            ],
            'timeout' => $connectTimeoutSeconds,
        ]);

        $this->browser = (new Browser($connector))
            ->withRejectErrorResponse(false)
            ->withTimeout(false);
    }

    public function open(string $method, string $url, array $headers, string $body): PromiseInterface
    {
        return $this->browser->requestStreaming($method, $url, $headers, $body)->then(
            static function (ResponseInterface $response): StreamingResponse {
                $body = $response->getBody();
                if (!$body instanceof ReadableStreamInterface) {
                    throw new RuntimeException('Streaming response body is not readable');
                }

                return new StreamingResponse($response->getStatusCode(), $body);
            },
        );
    }
}
