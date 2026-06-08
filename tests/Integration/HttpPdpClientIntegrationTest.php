<?php

declare(strict_types=1);

namespace Sapl\Tests\Integration;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Stream\ReadableStreamInterface;
use Sapl\Api\AuthorizationDecision;
use Sapl\Api\AuthorizationSubscription;
use Sapl\Api\Decision;
use Sapl\Pdp\Http\HttpPdpClient;
use Sapl\Pdp\Http\HttpPdpClientOptions;

/**
 * End-to-end tests of the HTTP client against a real SAPL Node container.
 *
 * Proves the on-the-wire contract: a one-shot permit decision, fail-closed to
 * INDETERMINATE when the node is unreachable, and a streaming decision over SSE.
 */
final class HttpPdpClientIntegrationTest extends TestCase
{
    private SaplNode $node;

    protected function setUp(): void
    {
        if (!SaplNode::imagePresent()) {
            self::markTestSkipped('SAPL Node image '.SaplNode::IMAGE.' not present.');
        }
        $this->node = new SaplNode();
        $this->node->start();
    }

    protected function tearDown(): void
    {
        $this->node->remove();
    }

    public function testDecideOnceReturnsPermit(): void
    {
        $client = $this->client();

        $decision = $client->decideOnce(new AuthorizationSubscription(subject: 'alice', action: 'read', resource: 'doc'));

        self::assertSame(Decision::PERMIT, $decision->decision);
    }

    public function testDecideOnceFailsClosedWhenNodeStopped(): void
    {
        $client = $this->client();
        $this->node->stop();

        $decision = $client->decideOnce(new AuthorizationSubscription(action: 'read'));

        self::assertSame(Decision::INDETERMINATE, $decision->decision);
    }

    public function testStreamingDecideYieldsPermit(): void
    {
        $client = $this->client();
        $stream = $client->decide(new AuthorizationSubscription(subject: 'alice', action: 'read', resource: 'doc'));

        $seen = $this->collectUntilFirst($stream, 5.0);

        self::assertNotEmpty($seen);
        self::assertSame(Decision::PERMIT, $seen[0]);
    }

    private function client(): HttpPdpClient
    {
        return new HttpPdpClient(new HttpPdpClientOptions($this->node->baseUrl(), timeoutSeconds: 5.0));
    }

    /**
     * Run the loop until the first decision arrives or the timeout elapses.
     *
     * @return list<Decision>
     */
    private function collectUntilFirst(ReadableStreamInterface $stream, float $maxSeconds): array
    {
        $seen = [];
        $stream->on('data', static function (AuthorizationDecision $decision) use (&$seen, $stream): void {
            $seen[] = $decision->decision;
            $stream->close();
            Loop::stop();
        });
        Loop::addTimer($maxSeconds, static function () use ($stream): void {
            $stream->close();
            Loop::stop();
        });
        Loop::run();

        return $seen;
    }
}
