<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Sapl\Api\AuthorizationSubscription;
use Sapl\Api\Decision;
use Sapl\Api\MultiAuthorizationSubscription;
use Sapl\Pdp\Http\HttpPdpClient;
use Sapl\Pdp\Http\HttpPdpClientOptions;
use Sapl\Pdp\Http\Transport\TransportException;
use Sapl\Pdp\Http\Transport\UnaryResponse;
use Sapl\Tests\Fake\FakeStreamingTransport;
use Sapl\Tests\Fake\FakeTokenProvider;
use Sapl\Tests\Fake\FakeUnaryTransport;

final class HttpPdpClientOneShotTest extends TestCase
{
    private const string BASE_URL = 'http://localhost:8443';

    public function testDecideOnceReturnsParsedPermit(): void
    {
        $client = $this->client(new FakeUnaryTransport(new UnaryResponse(200, '{"decision":"PERMIT"}')));

        self::assertSame(
            Decision::PERMIT,
            $client->decideOnce(new AuthorizationSubscription(action: 'read'))->decision,
        );
    }

    public function testDecideOnceFailsClosedOnTransportError(): void
    {
        $client = $this->client(new FakeUnaryTransport(new TransportException('connection refused')));

        self::assertSame(
            Decision::INDETERMINATE,
            $client->decideOnce(new AuthorizationSubscription(action: 'read'))->decision,
        );
    }

    public function testDecideOnceFailsClosedOnHttpErrorStatus(): void
    {
        $client = $this->client(new FakeUnaryTransport(new UnaryResponse(500, 'boom')));

        self::assertSame(
            Decision::INDETERMINATE,
            $client->decideOnce(new AuthorizationSubscription(action: 'read'))->decision,
        );
    }

    public function testDecideOnceFailsClosedOnInvalidJson(): void
    {
        $client = $this->client(new FakeUnaryTransport(new UnaryResponse(200, 'not-json')));

        self::assertSame(
            Decision::INDETERMINATE,
            $client->decideOnce(new AuthorizationSubscription(action: 'read'))->decision,
        );
    }

    public function testDecideOnceNeverRetriesOnTransportError(): void
    {
        $transport = new FakeUnaryTransport(new TransportException('down'));
        $this->client($transport)->decideOnce(new AuthorizationSubscription(action: 'read'));

        self::assertSame(1, $transport->calls);
    }

    public function testMultiDecideAllOnceParsesDecisions(): void
    {
        $client = $this->client(new FakeUnaryTransport(
            new UnaryResponse(200, '{"a":{"decision":"PERMIT"},"b":{"decision":"DENY"}}'),
        ));

        $result = $client->multiDecideAllOnce(new MultiAuthorizationSubscription([
            'a' => new AuthorizationSubscription(action: 'read'),
            'b' => new AuthorizationSubscription(action: 'write'),
        ]));

        self::assertSame(Decision::PERMIT, $result->decisions['a']->decision);
        self::assertSame(Decision::DENY, $result->decisions['b']->decision);
    }

    public function testMultiDecideAllOnceFailsClosedToEmpty(): void
    {
        $client = $this->client(new FakeUnaryTransport(new TransportException('down')));

        self::assertSame(
            [],
            $client->multiDecideAllOnce(new MultiAuthorizationSubscription())->decisions,
        );
    }

    public function testTokenIsSentAsBearerHeader(): void
    {
        $transport = new FakeUnaryTransport(new UnaryResponse(200, '{"decision":"PERMIT"}'));
        $options = new HttpPdpClientOptions(self::BASE_URL, token: 'secret-token');
        $client = new HttpPdpClient($options, $transport, new FakeStreamingTransport());

        $client->decideOnce(new AuthorizationSubscription(action: 'read'));

        self::assertSame('Bearer secret-token', $transport->received[0]['headers']['Authorization']);
    }

    public function testAuthFailureInvalidatesTokenAndRetriesOnce(): void
    {
        $transport = new FakeUnaryTransport(
            new UnaryResponse(401, 'unauthorized'),
            new UnaryResponse(200, '{"decision":"PERMIT"}'),
        );
        $tokenProvider = new FakeTokenProvider();
        $options = new HttpPdpClientOptions(self::BASE_URL, tokenProvider: $tokenProvider);
        $client = new HttpPdpClient($options, $transport, new FakeStreamingTransport());

        $decision = $client->decideOnce(new AuthorizationSubscription(action: 'read'));

        self::assertSame(Decision::PERMIT, $decision->decision);
        self::assertSame(2, $transport->calls);
        self::assertSame(1, $tokenProvider->invalidations);
    }

    public function testConstructorRejectsMixedAuth(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpPdpClient(
            new HttpPdpClientOptions(self::BASE_URL, token: 't', username: 'u', secret: 's'),
            new FakeUnaryTransport(),
            new FakeStreamingTransport(),
        );
    }

    public function testConstructorRejectsPlaintextHttpToNonLoopback(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpPdpClient(
            new HttpPdpClientOptions('http://pdp.example.com:8443'),
            new FakeUnaryTransport(),
            new FakeStreamingTransport(),
        );
    }

    private function client(FakeUnaryTransport $transport): HttpPdpClient
    {
        return new HttpPdpClient(
            new HttpPdpClientOptions(self::BASE_URL),
            $transport,
            new FakeStreamingTransport(),
        );
    }
}
