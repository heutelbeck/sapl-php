<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Symfony;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use React\Stream\ThroughStream;
use Sapl\Api\AuthorizationDecision;
use Sapl\Pep\Constraints\EnforcementPlanner;
use Sapl\Pep\Streaming\StreamingPolicyEnforcementPoint;
use Sapl\Symfony\AuthorizationSubscriptionBuilder;
use Sapl\Symfony\EnforcementSignals;
use Sapl\Symfony\StreamEnforce;
use Sapl\Symfony\StreamEnforcer;
use Sapl\Symfony\SubjectResolver;
use Sapl\Tests\Fake\RecordingStreamingPdp;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

final class StreamEnforcerTest extends TestCase
{
    private RecordingStreamingPdp $pdp;
    private StreamEnforcer $enforcer;

    protected function setUp(): void
    {
        $this->pdp = new RecordingStreamingPdp();
        $resolver = new class implements SubjectResolver {
            public function currentSubject(): mixed
            {
                return 'subscriber';
            }
        };
        $this->enforcer = new StreamEnforcer(
            $this->pdp,
            new StreamingPolicyEnforcementPoint(new EnforcementPlanner([]), EnforcementSignals::STREAM),
            new AuthorizationSubscriptionBuilder($resolver, new ExpressionLanguage()),
        );
    }

    #[Test]
    public function whenPermittedThenItemsFlowFromTheProtectedStreamToTheEnforcedStream(): void
    {
        $rap = new ThroughStream();
        $out = $this->enforcer->enforce(new StreamEnforce(action: 'beat'), 'App\\Service\\Heartbeat', 'beats', [], $rap);
        $items = [];
        $out->on('data', static function (mixed $value) use (&$items): void {
            $items[] = $value;
        });

        $this->pdp->decisions->write(AuthorizationDecision::permit());
        $rap->write('tick');
        $rap->write('tock');

        self::assertSame(['tick', 'tock'], $items);
        self::assertSame('beat', $this->pdp->lastSubscription?->action);
    }

    #[Test]
    public function whenTheProtectedMethodDidNotReturnAStreamThenItFailsClosed(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('#[StreamEnforce]');

        $this->enforcer->enforce(new StreamEnforce(), 'App\\Service\\Heartbeat', 'beats', [], 'not-a-stream');
    }
}
