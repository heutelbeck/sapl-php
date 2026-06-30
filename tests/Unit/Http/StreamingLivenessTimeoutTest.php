<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

use React\Stream\ThroughStream;
use Sapl\Api\AuthorizationDecision;
use Sapl\Api\AuthorizationSubscription;
use Sapl\Api\Decision;
use Sapl\Pdp\Http\HttpPdpClient;
use Sapl\Pdp\Http\HttpPdpClientOptions;
use Sapl\Pdp\Http\Transport\StreamingResponse;
use Sapl\Pdp\Reconnect\BackoffPolicy;
use Sapl\Tests\Fake\FakeStreamingTransport;
use Sapl\Tests\Fake\FakeUnaryTransport;

/**
 * Liveness contract for the streaming decision path (CR-PHP-01).
 *
 * A silently dead but still open SSE socket must not pin the consumer to a
 * stale decision. Mirroring the Spring remote PDP, the stream applies a
 * first-item timeout and then a per-item inactivity timeout: when the server
 * stops sending, the timeout fires, exactly one INDETERMINATE is seeded, and
 * the reconnect loop re-engages. Server-Sent-Event keep-alive comment frames
 * reset the inactivity window without counting as decisions.
 */
final class StreamingLivenessTimeoutTest extends TestCase
{
    private const string BASE_URL = 'http://localhost:8443';
    private const string PERMIT_FRAME = "data: {\"decision\":\"PERMIT\"}\n\n";
    private const string KEEP_ALIVE_FRAME = ": keep-alive\n\n";

    public function testSilentSocketAfterPermitFailsClosedAndReconnects(): void
    {
        $live = new ThroughStream();
        $dead = new ThroughStream();
        $streaming = new FakeStreamingTransport(
            static fn (): PromiseInterface => resolve(new StreamingResponse(200, $live)),
            static fn (): PromiseInterface => resolve(new StreamingResponse(200, $dead)),
        );

        $seen = $this->collect($streaming, 0.06, [
            [0.02, static function () use ($live): void {
                $live->write(self::PERMIT_FRAME);
                // No end, no error, no close: the socket is half-open and silent.
            }],
        ], 0.30);

        self::assertSame([Decision::PERMIT, Decision::INDETERMINATE], $seen);
        self::assertGreaterThanOrEqual(2, $streaming->calls, 'inactivity timeout must re-engage the reconnect loop');
    }

    public function testServerThatNeverSendsFirstDecisionFailsClosed(): void
    {
        $never = new ThroughStream();
        $retry = new ThroughStream();
        $streaming = new FakeStreamingTransport(
            static fn (): PromiseInterface => resolve(new StreamingResponse(200, $never)),
            static fn (): PromiseInterface => resolve(new StreamingResponse(200, $retry)),
        );

        $seen = $this->collect($streaming, 0.06, [], 0.30);

        self::assertSame([Decision::INDETERMINATE], $seen);
        self::assertGreaterThanOrEqual(2, $streaming->calls, 'a stream silent from the start must time out and reconnect');
    }

    public function testKeepAliveFramesResetLivenessWithoutSurfacingAsDecisions(): void
    {
        $live = new ThroughStream();
        $dead = new ThroughStream();
        $streaming = new FakeStreamingTransport(
            static fn (): PromiseInterface => resolve(new StreamingResponse(200, $live)),
            static fn (): PromiseInterface => resolve(new StreamingResponse(200, $dead)),
        );

        $duringKeepAlive = [];
        $seen = $this->collect($streaming, 0.10, [
            [0.02, static function () use ($live): void {
                $live->write(self::PERMIT_FRAME);
            }],
            [0.06, static function () use ($live): void {
                $live->write(self::KEEP_ALIVE_FRAME);
            }],
            [0.11, static function () use ($live): void {
                $live->write(self::KEEP_ALIVE_FRAME);
            }],
            [0.16, static function () use ($live): void {
                $live->write(self::KEEP_ALIVE_FRAME);
            }],
            [0.21, static function () use ($live): void {
                $live->write(self::KEEP_ALIVE_FRAME);
                // Then silence: the inactivity timeout must finally fire.
            }],
        ], 0.45, $duringKeepAlive, 0.27);

        self::assertSame([Decision::PERMIT], $duringKeepAlive, 'keep-alives must hold the stream on its last decision');
        self::assertSame([Decision::PERMIT, Decision::INDETERMINATE], $seen);
    }

    /**
     * Subscribe with the given inactivity timeout, run the loop firing the
     * scheduled feeds, and return the decision verbs emitted in order. When a
     * snapshot offset is supplied, the verbs seen up to that instant are copied
     * into $snapshot so a test can assert the stream's state mid-flight.
     *
     * @param list<array{0: float, 1: callable(): void}> $schedule offset seconds to action
     * @param list<Decision>                             $snapshot verbs seen up to $snapshotAt
     *
     * @return list<Decision>
     */
    private function collect(
        FakeStreamingTransport $streaming,
        float $inactivityTimeoutSeconds,
        array $schedule,
        float $stopAt,
        array &$snapshot = [],
        ?float $snapshotAt = null,
    ): array {
        $client = $this->client($streaming, $inactivityTimeoutSeconds);
        $stream = $client->decide(new AuthorizationSubscription(action: 'read'));

        $seen = [];
        $stream->on('data', static function (AuthorizationDecision $decision) use (&$seen): void {
            $seen[] = $decision->decision;
        });

        foreach ($schedule as [$offset, $action]) {
            Loop::addTimer($offset, $action);
        }
        if (null !== $snapshotAt) {
            Loop::addTimer($snapshotAt, static function () use (&$snapshot, &$seen): void {
                $snapshot = $seen;
            });
        }
        Loop::addTimer($stopAt, static function () use ($stream): void {
            $stream->close();
            Loop::stop();
        });
        Loop::run();

        return $seen;
    }

    private function client(FakeStreamingTransport $streaming, float $inactivityTimeoutSeconds): HttpPdpClient
    {
        return new HttpPdpClient(
            new HttpPdpClientOptions(
                self::BASE_URL,
                timeoutSeconds: $inactivityTimeoutSeconds,
                streamInactivityTimeoutSeconds: $inactivityTimeoutSeconds,
            ),
            new FakeUnaryTransport(),
            $streaming,
            null,
            new BackoffPolicy(0.001, 0.01, static fn (): float => 0.0),
        );
    }
}
