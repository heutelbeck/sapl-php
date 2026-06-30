<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sapl\Api\AuthorizationSubscription;
use Sapl\Api\Decision;
use Sapl\Api\MultiAuthorizationDecision;
use Sapl\Api\MultiAuthorizationSubscription;
use Sapl\Pdp\Http\HttpPdpClient;
use Sapl\Pdp\Http\HttpPdpClientOptions;
use Sapl\Pdp\Http\Transport\UnaryResponse;
use Sapl\Tests\Fake\FakeStreamingTransport;
use Sapl\Tests\Fake\FakeUnaryTransport;

/**
 * A multi-decision response that carries two entries for the same subscription
 * id is an error, not a merge. Per Spring (MultiAuthorizationDecisionDeserializer)
 * the decode boundary tracks seen ids and rejects a repeated id fail-closed
 * rather than silently last-wins-merging the entries. Collapsing duplicates is a
 * security defect: a later PERMIT would erase an earlier DENY for the same id.
 *
 * Traceability: DVW-11.
 */
final class MultiDecisionDuplicateSubscriptionIdTest extends TestCase
{
    private const string BASE_URL = 'http://localhost:8443';

    /**
     * @return iterable<string, array{string}>
     */
    public static function duplicateIdPayloads(): iterable
    {
        yield 'deny then permit' => ['{"read":{"decision":"DENY"},"read":{"decision":"PERMIT"}}'];
        yield 'permit then deny' => ['{"read":{"decision":"PERMIT"},"read":{"decision":"DENY"}}'];
        yield 'permit then permit' => ['{"read":{"decision":"PERMIT"},"read":{"decision":"PERMIT"}}'];
    }

    #[DataProvider('duplicateIdPayloads')]
    public function testDuplicateSubscriptionIdIsRejectedFailClosed(string $body): void
    {
        $result = $this->multiDecide($body);

        self::assertSame([], $result->decisions);
    }

    public function testEarlierDenyIsNeverErasedByLaterPermitForSameId(): void
    {
        $result = $this->multiDecide('{"read":{"decision":"DENY"},"read":{"decision":"PERMIT"}}');

        self::assertNotSame(
            Decision::PERMIT,
            $result->decisions['read']->decision ?? null,
            'A duplicate subscription id must not last-wins-merge a later PERMIT over an earlier DENY.',
        );
    }

    private function multiDecide(string $responseBody): MultiAuthorizationDecision
    {
        $client = new HttpPdpClient(
            new HttpPdpClientOptions(self::BASE_URL),
            new FakeUnaryTransport(new UnaryResponse(200, $responseBody)),
            new FakeStreamingTransport(),
        );

        return $client->multiDecideAllOnce(new MultiAuthorizationSubscription([
            'read' => new AuthorizationSubscription(action: 'read'),
        ]));
    }
}
