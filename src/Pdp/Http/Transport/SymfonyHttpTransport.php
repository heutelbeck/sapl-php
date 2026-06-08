<?php

declare(strict_types=1);

namespace Sapl\Pdp\Http\Transport;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Blocking unary transport over the synchronous Symfony HTTP client.
 *
 * No retry: a one-off is a point-in-time question, so a failure surfaces as a
 * {@see TransportException} for the client to fail closed. Retry would only add
 * latency and mask the outage.
 */
final class SymfonyHttpTransport implements UnaryHttpTransport
{
    private readonly HttpClientInterface $client;

    public function __construct(
        private readonly float $timeoutSeconds,
        ?HttpClientInterface $client = null,
    ) {
        $this->client = $client ?? HttpClient::create();
    }

    public function send(string $method, string $url, array $headers, string $body): UnaryResponse
    {
        try {
            $response = $this->client->request($method, $url, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => $this->timeoutSeconds,
            ]);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            return new UnaryResponse($statusCode, $content);
        } catch (ExceptionInterface $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }
}
