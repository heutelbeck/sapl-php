<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Pep;

use PHPUnit\Framework\TestCase;
use React\Stream\ReadableStreamInterface;
use React\Stream\ThroughStream;
use Sapl\Api\AuthorizationDecision;
use Sapl\Api\Decision;
use Sapl\Pep\Constraints\EnforcementPlanner;
use Sapl\Pep\Constraints\SignalKind;
use Sapl\Pep\Streaming\Granted;
use Sapl\Pep\Streaming\StreamingPolicyEnforcementPoint;
use Sapl\Pep\Streaming\SuspendedReason;
use Throwable;

final class StreamingPolicyEnforcementPointTest extends TestCase
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
            ->enforce($this->decisions, $this->rap, $this->signalTransitions());
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

    public function testPermitPassesItemsAndCompletesOnRapEnd(): void
    {
        $this->decisions->write(AuthorizationDecision::permit());
        $this->rap->write('a');
        $this->rap->write('b');
        $this->rap->end();

        self::assertSame(['a', 'b'], $this->data);
        self::assertTrue($this->ended);
        self::assertNull($this->errored);
    }

    public function testDenyErrorsAndDoesNotComplete(): void
    {
        $this->decisions->write(AuthorizationDecision::permit());
        $this->rap->write('a');
        $this->decisions->write(AuthorizationDecision::deny());
        $this->rap->write('after-deny');

        self::assertSame(['a'], $this->data);
        self::assertNotNull($this->errored);
        self::assertFalse($this->ended);
    }

    public function testSuspendDropsItemsUntilResume(): void
    {
        $this->decisions->write(AuthorizationDecision::permit());
        $this->rap->write('first');
        $this->decisions->write(new AuthorizationDecision(Decision::SUSPEND));
        $this->rap->write('dropped');
        $this->decisions->write(AuthorizationDecision::permit());
        $this->rap->write('resumed');

        self::assertSame(['first', 'resumed'], $this->data);
    }

    public function testTransitionSignallingWritesBoundaryFrames(): void
    {
        $decisions = new ThroughStream();
        $rap = new ThroughStream();
        $out = (new StreamingPolicyEnforcementPoint(new EnforcementPlanner([]), self::SIGNALS))
            ->enforce($decisions, $rap, true);
        $frames = [];
        $out->on('data', static function (mixed $value) use (&$frames): void {
            $frames[] = $value;
        });

        $decisions->write(AuthorizationDecision::permit());
        $decisions->write(new AuthorizationDecision(Decision::SUSPEND));
        $decisions->write(AuthorizationDecision::permit());

        self::assertContainsOnly('object', $frames);
        self::assertInstanceOf(Granted::class, $frames[0]);
        self::assertInstanceOf(SuspendedReason::class, $frames[1]);
        self::assertInstanceOf(Granted::class, $frames[2]);
    }

    public function testPauseRapDuringSuspendPausesOnSuspendAndResumesOnPermit(): void
    {
        $decisions = new ThroughStream();
        $rap = $this->createMock(ReadableStreamInterface::class);
        $rap->expects(self::once())->method('pause');
        $rap->expects(self::exactly(2))->method('resume');
        (new StreamingPolicyEnforcementPoint(new EnforcementPlanner([]), self::SIGNALS))
            ->enforce($decisions, $rap, false, true);

        $decisions->write(AuthorizationDecision::permit());
        $decisions->write(new AuthorizationDecision(Decision::SUSPEND));
        $decisions->write(AuthorizationDecision::permit());
    }

    public function testWithoutPauseFlagTheItemStreamIsNeverPaused(): void
    {
        $decisions = new ThroughStream();
        $rap = $this->createMock(ReadableStreamInterface::class);
        $rap->expects(self::never())->method('pause');
        (new StreamingPolicyEnforcementPoint(new EnforcementPlanner([]), self::SIGNALS))
            ->enforce($decisions, $rap, false, false);

        $decisions->write(AuthorizationDecision::permit());
        $decisions->write(new AuthorizationDecision(Decision::SUSPEND));
    }

    private function signalTransitions(): bool
    {
        return false;
    }
}
