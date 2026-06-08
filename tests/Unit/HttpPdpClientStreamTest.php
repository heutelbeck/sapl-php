<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

use React\Stream\ThroughStream;
use RuntimeException;
use Sapl\Api\AuthorizationDecision;
use Sapl\Api\AuthorizationSubscription;
use Sapl\Api\Decision;
use Sapl\Pdp\Http\HttpPdpClient;
use Sapl\Pdp\Http\HttpPdpClientOptions;
use Sapl\Pdp\Http\Transport\StreamingResponse;
use Sapl\Pdp\Reconnect\BackoffPolicy;
use Sapl\Tests\Fake\FakeStreamingTransport;
use Sapl\Tests\Fake\FakeUnaryTransport;

final class HttpPdpClientStreamTest extends TestCase
{
    private const string BASE_URL = 'http://localhost:8443';
    private const string PERMIT_FRAME = "data: {\"decision\":\"PERMIT\"}\n\n";

    public function testGracefulCompleteReconnectsSeedsIndeterminateAndDedups(): void
    {
        $bodyA = new ThroughStream();
        $bodyB = new ThroughStream();
        $streaming = new FakeStreamingTransport(
            static fn (): PromiseInterface => resolve(new StreamingResponse(200, $bodyA)),
            static fn (): PromiseInterface => resolve(new StreamingResponse(200, $bodyB)),
        );

        $seen = $this->collect($streaming, [
            [0.02, static function () use ($bodyA): void {
                $bodyA->write(self::PERMIT_FRAME);
                $bodyA->end();
            }],
            [0.08, static function () use ($bodyB): void {
                $bodyB->write(self::PERMIT_FRAME);
                $bodyB->write(self::PERMIT_FRAME);
            }],
        ], 0.16);

        self::assertSame([Decision::PERMIT, Decision::INDETERMINATE, Decision::PERMIT], $seen);
        self::assertSame(2, $streaming->calls);
    }

    public function testConnectionRejectionReconnectsWithoutTerminating(): void
    {
        $body = new ThroughStream();
        $streaming = new FakeStreamingTransport(
            static fn (): PromiseInterface => reject(new RuntimeException('connection refused')),
            static fn (): PromiseInterface => resolve(new StreamingResponse(200, $body)),
        );

        $ended = false;
        $errored = false;
        $seen = $this->collect($streaming, [
            [0.05, static function () use ($body): void {
                $body->write(self::PERMIT_FRAME);
            }],
        ], 0.12, $ended, $errored);

        self::assertSame([Decision::INDETERMINATE, Decision::PERMIT], $seen);
        self::assertFalse($ended, 'a subscription must not end on a transport condition');
        self::assertFalse($errored, 'a transport error must not surface to the consumer');
    }

    public function testConsumerCloseStopsReconnecting(): void
    {
        $streaming = new FakeStreamingTransport(
            static fn (): PromiseInterface => reject(new RuntimeException('down')),
            static fn (): PromiseInterface => reject(new RuntimeException('down')),
            static fn (): PromiseInterface => reject(new RuntimeException('down')),
            static fn (): PromiseInterface => reject(new RuntimeException('down')),
        );
        $client = $this->client($streaming);
        $stream = $client->decide(new AuthorizationSubscription(action: 'read'));

        $callsAtClose = 0;
        Loop::addTimer(0.03, static function () use ($stream, $streaming, &$callsAtClose): void {
            $stream->close();
            $callsAtClose = $streaming->calls;
        });
        Loop::addTimer(0.12, static function (): void {
            Loop::stop();
        });
        Loop::run();

        self::assertSame($callsAtClose, $streaming->calls, 'no reconnect attempt after the consumer closes');
    }

    /**
     * Subscribe, run the loop firing the scheduled feeds, and return the
     * decision verbs emitted in order.
     *
     * @param list<array{0: float, 1: callable(): void}> $schedule offset seconds to action
     *
     * @return list<Decision>
     */
    private function collect(
        FakeStreamingTransport $streaming,
        array $schedule,
        float $stopAt,
        bool &$ended = false,
        bool &$errored = false,
    ): array {
        $client = $this->client($streaming);
        $stream = $client->decide(new AuthorizationSubscription(action: 'read'));

        $seen = [];
        $stream->on('data', static function (AuthorizationDecision $decision) use (&$seen): void {
            $seen[] = $decision->decision;
        });
        $stream->on('end', static function () use (&$ended): void {
            $ended = true;
        });
        $stream->on('error', static function () use (&$errored): void {
            $errored = true;
        });

        foreach ($schedule as [$offset, $action]) {
            Loop::addTimer($offset, $action);
        }
        Loop::addTimer($stopAt, static function () use ($stream): void {
            $stream->close();
            Loop::stop();
        });
        Loop::run();

        return $seen;
    }

    private function client(FakeStreamingTransport $streaming): HttpPdpClient
    {
        return new HttpPdpClient(
            new HttpPdpClientOptions(self::BASE_URL),
            new FakeUnaryTransport(),
            $streaming,
            null,
            new BackoffPolicy(0.001, 0.01, static fn (): float => 0.0),
        );
    }
}
