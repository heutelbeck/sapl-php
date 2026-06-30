<?php

declare(strict_types=1);

namespace Sapl\Pdp\Http;

use Closure;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;
use Sapl\Pdp\Http\Transport\StreamingHttpTransport;
use Sapl\Pdp\Http\Transport\StreamingResponse;
use Sapl\Pdp\Reconnect\BackoffPolicy;
use Throwable;

/**
 * Drives one streaming subscription against the resilience contract: never
 * terminate on a transport condition.
 *
 * A transport error, an HTTP error status, an SSE buffer overflow, a server
 * graceful completion, or a silent stall past the inactivity window all seed
 * INDETERMINATE and reconnect with bounded exponential backoff, forever.
 * Consecutive equal emissions are suppressed. The subscription ends only when
 * the consumer closes the returned stream.
 */
final class StreamReconnector
{
    private readonly ThroughStream $out;

    private int $attempt = 0;
    private ?object $last = null;
    private bool $closed = false;
    private bool $reconnecting = false;
    private ?ReadableStreamInterface $bodyStream = null;
    private ?TimerInterface $livenessTimer = null;

    /**
     * @param array<string, string>   $headers
     * @param Closure(mixed): ?object $parse                    decode one SSE frame, or null on a decode failure that fails stale
     * @param Closure(): list<object> $seed                     INDETERMINATE values emitted across a gap
     * @param Closure(): void         $onAuthFailure            invoked on HTTP 401/403 to invalidate a token
     * @param float                   $firstItemTimeoutSeconds  silence allowed before the first frame arrives
     * @param float                   $inactivityTimeoutSeconds silence allowed between frames once streaming
     */
    public function __construct(
        private readonly StreamingHttpTransport $transport,
        private readonly BackoffPolicy $backoff,
        private readonly LoggerInterface $logger,
        private readonly string $url,
        private readonly string $body,
        private readonly array $headers,
        private readonly Closure $parse,
        private readonly Closure $seed,
        private readonly Closure $onAuthFailure,
        private readonly float $firstItemTimeoutSeconds,
        private readonly float $inactivityTimeoutSeconds,
    ) {
        $this->out = new ThroughStream();
    }

    public function start(): ReadableStreamInterface
    {
        $this->out->on('close', function (): void {
            $this->closed = true;
            $this->cancelLiveness();
            $this->bodyStream?->close();
            $this->bodyStream = null;
        });
        Loop::futureTick(function (): void {
            $this->connect();
        });

        return $this->out;
    }

    private function connect(): void
    {
        if ($this->closed) {
            return;
        }
        $this->reconnecting = false;
        $this->transport->open('POST', $this->url, $this->headers, $this->body)->then(
            function (StreamingResponse $response): void {
                $this->onResponse($response);
            },
            function (Throwable $error): void {
                $this->logger->warning('sapl.pdp_streaming_connection_lost', [
                    'url' => $this->url,
                    'error' => $error->getMessage(),
                ]);
                $this->scheduleReconnect();
            },
        );
    }

    private function onResponse(StreamingResponse $response): void
    {
        if ($this->closed) {
            $response->body->close();

            return;
        }
        if ($response->statusCode >= 400) {
            $this->logger->error('sapl.pdp_streaming_http_error', [
                'url' => $this->url,
                'status' => $response->statusCode,
            ]);
            if (401 === $response->statusCode || 403 === $response->statusCode) {
                ($this->onAuthFailure)();
            }
            $response->body->close();
            $this->scheduleReconnect();

            return;
        }

        $sse = new SseFrameParser();
        $this->bodyStream = $response->body;
        $this->armLiveness($this->firstItemTimeoutSeconds);
        $response->body->on('data', function (string $chunk) use ($sse): void {
            $this->onChunk($sse, $chunk);
        });
        $response->body->on('error', function (): void {
            $this->scheduleReconnect();
        });
        $response->body->on('close', function (): void {
            $this->bodyStream = null;
            $this->scheduleReconnect();
        });
    }

    private function onChunk(SseFrameParser $sse, string $chunk): void
    {
        // Any byte from the server, including a keep-alive comment frame that
        // yields no decision, proves the socket is alive and resets the window.
        $this->armLiveness($this->inactivityTimeoutSeconds);
        try {
            $frames = $sse->push($chunk);
        } catch (SseBufferOverflowException) {
            $this->logger->error('sapl.sse_buffer_overflow', ['url' => $this->url]);
            $this->bodyStream?->close();

            return;
        }
        foreach ($frames as $raw) {
            $item = ($this->parse)($raw);
            if (null === $item) {
                // A frame that decodes as JSON but cannot be interpreted as a
                // decision is a fail-stale condition: surface INDETERMINATE via
                // the seed and reconnect, rather than dropping it and leaving
                // the consumer on the previous (possibly PERMIT) decision.
                $this->logger->error('sapl.pdp_streaming_decode_error', ['url' => $this->url]);
                $this->bodyStream?->close();
                $this->bodyStream = null;
                $this->scheduleReconnect();

                return;
            }
            $this->attempt = 0;
            $this->emit($item);
        }
    }

    private function scheduleReconnect(): void
    {
        if ($this->closed || $this->reconnecting) {
            return;
        }
        $this->cancelLiveness();
        $this->reconnecting = true;
        ++$this->attempt;
        $delay = $this->backoff->delayForAttempt($this->attempt);
        $this->logger->log(
            $this->backoff->logLevelForAttempt($this->attempt),
            'sapl.pdp_streaming_reconnect',
            ['attempt' => $this->attempt, 'delay' => round($delay, 3)],
        );
        foreach (($this->seed)() as $item) {
            $this->emit($item);
        }
        Loop::addTimer($delay, function (): void {
            $this->connect();
        });
    }

    private function armLiveness(float $seconds): void
    {
        $this->cancelLiveness();
        $this->livenessTimer = Loop::addTimer($seconds, function (): void {
            $this->onInactivityTimeout();
        });
    }

    private function cancelLiveness(): void
    {
        if (null !== $this->livenessTimer) {
            Loop::cancelTimer($this->livenessTimer);
            $this->livenessTimer = null;
        }
    }

    private function onInactivityTimeout(): void
    {
        $this->livenessTimer = null;
        $this->logger->warning('sapl.pdp_streaming_inactivity_timeout', [
            'url' => $this->url,
            'timeout' => round($this->inactivityTimeoutSeconds, 3),
        ]);
        $this->bodyStream?->close();
        $this->bodyStream = null;
        $this->scheduleReconnect();
    }

    private function emit(object $item): void
    {
        if ($item != $this->last) {
            $this->last = $item;
            $this->out->write($item);
        }
    }
}
