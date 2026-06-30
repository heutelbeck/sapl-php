<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Pep;

use PHPUnit\Framework\TestCase;
use React\Stream\ThroughStream;
use Sapl\Api\AuthorizationDecision;
use Sapl\Pep\Constraints\EnforcementPlanner;
use Sapl\Pep\Constraints\SignalKind;
use Sapl\Pep\Streaming\StreamingPolicyEnforcementPoint;
use Throwable;

/**
 * A streaming PDP decision stream is contractually infinite. If it completes,
 * the PEP must treat the PDP as defective and fail closed rather than letting
 * the protected stream run on under a stale grant. An empty decision stream
 * must be coerced to a single terminating DENY. (fsm-pdp-complete-not-failclosed).
 */
final class StreamingPdpCompletionFailClosedTest extends TestCase
{
    /** @var list<SignalKind> */
    private const array SIGNALS = [SignalKind::DECISION, SignalKind::OUTPUT, SignalKind::ERROR];

    private ThroughStream $decisions;
    private ThroughStream $rap;

    /** @var list<mixed> */
    private array $data = [];
    private bool $ended = false;
    private ?Throwable $errored = null;

    protected function setUp(): void
    {
        $this->decisions = new ThroughStream();
        $this->rap = new ThroughStream();
        $out = (new StreamingPolicyEnforcementPoint(new EnforcementPlanner([]), self::SIGNALS))
            ->enforce($this->decisions, $this->rap);
        $out->on('data', function (mixed $value): void {
            $this->data[] = $value;
        });
        $out->on('end', function (): void {
            $this->ended = true;
        });
        $out->on('error', function (Throwable $error): void {
            $this->errored = $error;
        });
    }

    public function testPdpCompletionWhilePermittingFailsClosedWithError(): void
    {
        $this->decisions->write(AuthorizationDecision::permit());
        $this->rap->write('a');
        $this->decisions->end();

        self::assertNotNull($this->errored, 'PDP completion must fail closed with an error');
        self::assertFalse($this->ended, 'a defective PDP completion is an error, not a normal completion');
    }

    public function testItemsDoNotFlowAfterPdpCompletionUnderStaleGrant(): void
    {
        $this->decisions->write(AuthorizationDecision::permit());
        $this->rap->write('a');
        $this->decisions->end();
        $this->rap->write('after-completion');

        self::assertSame(['a'], $this->data, 'no item may flow under a grant whose PDP stream has completed');
    }

    public function testEmptyDecisionStreamIsCoercedToTerminatingDeny(): void
    {
        $this->decisions->end();

        self::assertNotNull($this->errored, 'an empty decision stream must be coerced to a terminating DENY');
        self::assertFalse($this->ended);
    }

    public function testEmptyDecisionStreamDeniesPendingItems(): void
    {
        $this->decisions->end();
        $this->rap->write('never-authorised');

        self::assertSame([], $this->data, 'pending items must not flow once the empty stream denies');
        self::assertNotNull($this->errored);
    }
}
