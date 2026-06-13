<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Pep;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sapl\Api\AuthorizationDecision;
use Sapl\Api\Decision;
use Sapl\Pep\Constraints\EnforcementPlanner;
use Sapl\Pep\Constraints\Mapper;
use Sapl\Pep\Constraints\Runner;
use Sapl\Pep\Constraints\ScopedHandler;
use Sapl\Pep\Constraints\SignalKind;
use Sapl\Pep\Streaming\Emission;
use Sapl\Pep\Streaming\Emit;
use Sapl\Pep\Streaming\EmitComplete;
use Sapl\Pep\Streaming\EmitError;
use Sapl\Pep\Streaming\EmitTransition;
use Sapl\Pep\Streaming\Granted;
use Sapl\Pep\Streaming\MealyMachine;
use Sapl\Pep\Streaming\StreamingEnforcementDriver;
use Sapl\Tests\Fake\FakeConstraintHandlerProvider;

final class StreamingEnforcementDriverTest extends TestCase
{
    /** @var list<SignalKind> */
    private const array SIGNALS = [SignalKind::DECISION, SignalKind::OUTPUT, SignalKind::ERROR];

    public function testPermitThenItemEmitsGrantedThenValue(): void
    {
        $driver = $this->driver();

        self::assertSame(['granted'], $this->tags($driver->onDecision(AuthorizationDecision::permit())));
        self::assertSame(['emit'], $this->tags($driver->onItem('hello')));
        self::assertFalse($driver->isTerminated());
    }

    public function testSuspendDropsItemsThenResumeEmitsGranted(): void
    {
        $driver = $this->driver();
        $driver->onDecision(AuthorizationDecision::permit());

        self::assertSame(['suspended'], $this->tags($driver->onDecision(new AuthorizationDecision(Decision::SUSPEND))));
        self::assertSame([], $this->tags($driver->onItem('dropped')));
        self::assertSame(['granted'], $this->tags($driver->onDecision(AuthorizationDecision::permit())));
        self::assertSame(['emit'], $this->tags($driver->onItem('shown')));
    }

    public function testDenyTerminates(): void
    {
        $driver = $this->driver();

        self::assertSame(['error'], $this->tags($driver->onDecision(AuthorizationDecision::deny())));
        self::assertTrue($driver->isTerminated());
    }

    public function testDenyStillEnforcesDecisionScopedObligation(): void
    {
        $ran = false;
        $decision = new AuthorizationDecision(Decision::DENY, [['type' => 'audit']]);
        $provider = new FakeConstraintHandlerProvider('audit', [
            new ScopedHandler(new Runner(static function () use (&$ran): void {
                $ran = true;
            }), SignalKind::DECISION, 0),
        ]);
        $driver = $this->driver($provider);

        self::assertSame(['error'], $this->tags($driver->onDecision($decision)));
        self::assertTrue($ran, 'decision-scoped obligation handler must run before the deny');
    }

    public function testSuspendStillEnforcesDecisionScopedObligation(): void
    {
        $ran = false;
        $decision = new AuthorizationDecision(Decision::SUSPEND, [['type' => 'audit']]);
        $provider = new FakeConstraintHandlerProvider('audit', [
            new ScopedHandler(new Runner(static function () use (&$ran): void {
                $ran = true;
            }), SignalKind::DECISION, 0),
        ]);
        $driver = $this->driver($provider);

        self::assertSame(['suspended'], $this->tags($driver->onDecision($decision)));
        self::assertTrue($ran, 'decision-scoped obligation handler must run on suspend');
    }

    public function testIndeterminateDeniesWithIndeterminateMessage(): void
    {
        $driver = $this->driver();

        $emissions = $driver->onDecision(new AuthorizationDecision(Decision::INDETERMINATE));

        $error = $emissions[0];
        self::assertInstanceOf(EmitError::class, $error);
        self::assertSame(MealyMachine::DENIED_INDETERMINATE, $error->error->getMessage());
    }

    public function testPermitWithFailedDecisionObligationIsDowngradedToDeny(): void
    {
        $decision = new AuthorizationDecision(Decision::PERMIT, [['type' => 'fail']]);
        $provider = new FakeConstraintHandlerProvider('fail', [
            new ScopedHandler(new Runner(static fn () => throw new RuntimeException('x')), SignalKind::DECISION, 0),
        ]);
        $driver = $this->driver($provider);

        $emissions = $driver->onDecision($decision);

        $error = $emissions[0];
        self::assertInstanceOf(EmitError::class, $error);
        self::assertSame(MealyMachine::DENIED_PERMIT_NOT_ENFORCEABLE, $error->error->getMessage());
    }

    public function testPerItemOutputMapperTransformsTheEmittedValue(): void
    {
        $decision = new AuthorizationDecision(Decision::PERMIT, [['type' => 'shout']]);
        $provider = new FakeConstraintHandlerProvider('shout', [
            new ScopedHandler(new Mapper(static fn (mixed $v): mixed => is_string($v) ? strtoupper($v) : $v), SignalKind::OUTPUT, 0),
        ]);
        $driver = $this->driver($provider);
        $driver->onDecision($decision);

        $emission = $driver->onItem('hi')[0];

        self::assertInstanceOf(Emit::class, $emission);
        self::assertSame('HI', $emission->value);
    }

    public function testPerItemObligationFailureTerminates(): void
    {
        $decision = new AuthorizationDecision(Decision::PERMIT, [['type' => 'boom']]);
        $provider = new FakeConstraintHandlerProvider('boom', [
            new ScopedHandler(new Runner(static fn () => throw new RuntimeException('x')), SignalKind::OUTPUT, 0),
        ]);
        $driver = $this->driver($provider);
        $driver->onDecision($decision);

        self::assertSame(['error'], $this->tags($driver->onItem('x')));
        self::assertTrue($driver->isTerminated());
    }

    public function testRapCompleteCompletes(): void
    {
        $driver = $this->driver();
        $driver->onDecision(AuthorizationDecision::permit());

        self::assertSame(['complete'], $this->tags($driver->onRapComplete()));
        self::assertTrue($driver->isTerminated());
    }

    public function testTerminatedDriverIgnoresFurtherItems(): void
    {
        $driver = $this->driver();
        $driver->onDecision(AuthorizationDecision::deny());

        self::assertSame([], $this->tags($driver->onItem('x')));
    }

    public function testGrantedCarriesDecisionForBoundarySignalling(): void
    {
        $driver = $this->driver();

        $emission = $driver->onDecision(AuthorizationDecision::permit())[0];

        self::assertInstanceOf(EmitTransition::class, $emission);
        self::assertInstanceOf(Granted::class, $emission->reason);
    }

    private function driver(FakeConstraintHandlerProvider ...$providers): StreamingEnforcementDriver
    {
        return new StreamingEnforcementDriver(new EnforcementPlanner(array_values($providers)), self::SIGNALS);
    }

    /**
     * @param list<Emission> $emissions
     *
     * @return list<string>
     */
    private function tags(array $emissions): array
    {
        return array_map(static fn (object $emission): string => match (true) {
            $emission instanceof Emit => 'emit',
            $emission instanceof EmitError => 'error',
            $emission instanceof EmitComplete => 'complete',
            $emission instanceof EmitTransition => $emission->reason instanceof Granted ? 'granted' : 'suspended',
            default => 'unknown',
        }, $emissions);
    }
}
