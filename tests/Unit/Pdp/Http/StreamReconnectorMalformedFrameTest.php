<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Pdp\Http;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

use React\Stream\ThroughStream;
use Sapl\Api\AuthorizationSubscription;
use Sapl\Api\Decision;
use Sapl\Api\IdentifiableAuthorizationDecision;
use Sapl\Api\MultiAuthorizationDecision;
use Sapl\Api\MultiAuthorizationSubscription;
use Sapl\Pdp\Http\HttpPdpClient;
use Sapl\Pdp\Http\HttpPdpClientOptions;
use Sapl\Pdp\Http\Transport\StreamingResponse;
use Sapl\Pdp\Reconnect\BackoffPolicy;
use Sapl\Tests\Fake\FakeStreamingTransport;
use Sapl\Tests\Fake\FakeUnaryTransport;

/**
 * A malformed frame on a multi/identifiable streaming subscription must fail
 * stale: the consumer is moved to INDETERMINATE rather than being left on the
 * previous (possibly PERMIT) decision. Mirrors the Spring HTTP PDP client,
 * where a decode failure on the SSE flux surfaces as a stream error that emits
 * INDETERMINATE and reconnects (CR-PHP-02).
 */
final class StreamReconnectorMalformedFrameTest extends TestCase
{
    private const string BASE_URL = 'http://localhost:8443';
    private const string SUBSCRIPTION_ID = 'sub';
    private const string IDENTIFIABLE_PERMIT_FRAME =
        "data: {\"subscriptionId\":\"sub\",\"decision\":{\"decision\":\"PERMIT\"}}\n\n";
    private const string NON_OBJECT_FRAME = "data: \"not-an-object\"\n\n";
    private const string MULTI_PERMIT_FRAME = "data: {\"sub\":{\"decision\":\"PERMIT\"}}\n\n";

    public function testMultiDecideMalformedIdentifiableFrameSubstitutesIndeterminate(): void
    {
        $bodyA = new ThroughStream();
        $bodyB = new ThroughStream();
        $streaming = new FakeStreamingTransport(
            static fn (): PromiseInterface => resolve(new StreamingResponse(200, $bodyA)),
            static fn (): PromiseInterface => resolve(new StreamingResponse(200, $bodyB)),
        );

        $client = $this->client($streaming);
        $stream = $client->multiDecide($this->singleSubscription());

        $seen = [];
        $stream->on('data', static function (IdentifiableAuthorizationDecision $decision) use (&$seen): void {
            $seen[] = $decision->decision->decision;
        });

        Loop::addTimer(0.02, static function () use ($bodyA): void {
            $bodyA->write(self::IDENTIFIABLE_PERMIT_FRAME);
            $bodyA->write(self::NON_OBJECT_FRAME);
        });
        Loop::addTimer(0.12, static function () use ($stream): void {
            $stream->close();
            Loop::stop();
        });
        Loop::run();

        self::assertSame(
            [Decision::PERMIT, Decision::INDETERMINATE],
            $seen,
            'a malformed identifiable frame must move the consumer to INDETERMINATE, not be silently dropped',
        );
    }

    public function testMultiDecideAllMalformedBundleFrameSubstitutesIndeterminate(): void
    {
        $bodyA = new ThroughStream();
        $bodyB = new ThroughStream();
        $streaming = new FakeStreamingTransport(
            static fn (): PromiseInterface => resolve(new StreamingResponse(200, $bodyA)),
            static fn (): PromiseInterface => resolve(new StreamingResponse(200, $bodyB)),
        );

        $client = $this->client($streaming);
        $stream = $client->multiDecideAll($this->singleSubscription());

        $seen = [];
        $stream->on('data', static function (MultiAuthorizationDecision $bundle) use (&$seen): void {
            $seen[] = $bundle->decisions[self::SUBSCRIPTION_ID]->decision;
        });

        Loop::addTimer(0.02, static function () use ($bodyA): void {
            $bodyA->write(self::MULTI_PERMIT_FRAME);
            $bodyA->write(self::NON_OBJECT_FRAME);
        });
        Loop::addTimer(0.12, static function () use ($stream): void {
            $stream->close();
            Loop::stop();
        });
        Loop::run();

        self::assertSame(
            [Decision::PERMIT, Decision::INDETERMINATE],
            $seen,
            'a malformed bundle frame must move the consumer to INDETERMINATE, not be silently dropped',
        );
    }

    private function singleSubscription(): MultiAuthorizationSubscription
    {
        return new MultiAuthorizationSubscription([
            self::SUBSCRIPTION_ID => new AuthorizationSubscription(action: 'read'),
        ]);
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
