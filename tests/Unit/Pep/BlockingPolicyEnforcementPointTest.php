<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Pep;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sapl\Api\AuthorizationDecision;
use Sapl\Api\AuthorizationSubscription;
use Sapl\Api\Decision;
use Sapl\Pep\AccessDeniedException;
use Sapl\Pep\BlockingPolicyEnforcementPoint;
use Sapl\Pep\Constraints\EnforcementPlanner;
use Sapl\Pep\Constraints\Mapper;
use Sapl\Pep\Constraints\Runner;
use Sapl\Pep\Constraints\ScopedHandler;
use Sapl\Pep\Constraints\SignalKind;
use Sapl\Pep\MethodInvocation;
use Sapl\Tests\Fake\FakeConstraintHandlerProvider;
use Sapl\Tests\Fake\FakePolicyDecisionPoint;
use Throwable;

final class BlockingPolicyEnforcementPointTest extends TestCase
{
    /** @var list<SignalKind> */
    private const array PRE_SIGNALS = [SignalKind::DECISION, SignalKind::INPUT, SignalKind::OUTPUT, SignalKind::ERROR];

    /** @var list<SignalKind> */
    private const array POST_SIGNALS = [SignalKind::DECISION, SignalKind::OUTPUT, SignalKind::ERROR];

    public function testPreEnforcePermitInvokesAndReturnsResult(): void
    {
        $invoked = false;
        $result = $this->pep(AuthorizationDecision::permit())->preEnforce(
            $this->subscription(),
            self::PRE_SIGNALS,
            $this->invocation([], function () use (&$invoked): string {
                $invoked = true;

                return 'result';
            }),
        );

        self::assertTrue($invoked);
        self::assertSame('result', $result);
    }

    public function testPreEnforceDenyThrowsAndDoesNotInvoke(): void
    {
        $invoked = false;
        $invocation = $this->invocation([], function () use (&$invoked): string {
            $invoked = true;

            return 'x';
        });

        $this->expectException(AccessDeniedException::class);

        try {
            $this->pep(AuthorizationDecision::deny())->preEnforce($this->subscription(), self::PRE_SIGNALS, $invocation);
        } finally {
            self::assertFalse($invoked);
        }
    }

    public function testPreEnforceSuspendIsTreatedAsDeny(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->pep(new AuthorizationDecision(Decision::SUSPEND))->preEnforce(
            $this->subscription(),
            self::PRE_SIGNALS,
            $this->invocation([], static fn (): string => 'x'),
        );
    }

    public function testPreEnforceUnhandledObligationDeniesAndDoesNotInvoke(): void
    {
        $invoked = false;
        $decision = new AuthorizationDecision(Decision::PERMIT, [['type' => 'unknown']]);
        $invocation = $this->invocation([], function () use (&$invoked): string {
            $invoked = true;

            return 'x';
        });

        $this->expectException(AccessDeniedException::class);

        try {
            $this->pep($decision)->preEnforce($this->subscription(), self::PRE_SIGNALS, $invocation);
        } finally {
            self::assertFalse($invoked);
        }
    }

    public function testPreEnforceOutputMapperTransformsResult(): void
    {
        $decision = new AuthorizationDecision(Decision::PERMIT, [['type' => 'shout']]);
        $provider = new FakeConstraintHandlerProvider('shout', [
            new ScopedHandler(new Mapper(static fn (mixed $v): mixed => is_string($v) ? strtoupper($v) : $v), SignalKind::OUTPUT, 0),
        ]);

        $result = $this->pep($decision, $provider)->preEnforce(
            $this->subscription(),
            self::PRE_SIGNALS,
            $this->invocation([], static fn (): string => 'hi'),
        );

        self::assertSame('HI', $result);
    }

    public function testPreEnforceInputMapperMutatesArguments(): void
    {
        $decision = new AuthorizationDecision(Decision::PERMIT, [['type' => 'cap']]);
        $provider = new FakeConstraintHandlerProvider('cap', [
            new ScopedHandler(new Mapper(static function (mixed $invocation): mixed {
                if ($invocation instanceof MethodInvocation) {
                    $invocation->arguments = [5000];
                }

                return $invocation;
            }), SignalKind::INPUT, 0),
        ]);

        $result = $this->pep($decision, $provider)->preEnforce(
            $this->subscription(),
            self::PRE_SIGNALS,
            $this->invocation([8000], static fn (array $args): mixed => $args[0]),
        );

        self::assertSame(5000, $result);
    }

    public function testPreEnforceErrorMapperReplacesThrownException(): void
    {
        $decision = new AuthorizationDecision(Decision::PERMIT, [['type' => 'wrap']]);
        $provider = new FakeConstraintHandlerProvider('wrap', [
            new ScopedHandler(new Mapper(static fn (mixed $e): mixed => new RuntimeException('wrapped')), SignalKind::ERROR, 0),
        ]);
        $invocation = $this->invocation([], static fn () => throw new RuntimeException('original'));

        $thrown = $this->capture(fn () => $this->pep($decision, $provider)->preEnforce($this->subscription(), self::PRE_SIGNALS, $invocation));

        self::assertInstanceOf(RuntimeException::class, $thrown);
        self::assertSame('wrapped', $thrown->getMessage());
    }

    public function testPostEnforcePermitReturnsValue(): void
    {
        $result = $this->pep(AuthorizationDecision::permit())->postEnforce(
            $this->subscription(),
            self::POST_SIGNALS,
            'value',
        );

        self::assertSame('value', $result);
    }

    public function testPostEnforceDenyThrows(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->pep(AuthorizationDecision::deny())->postEnforce($this->subscription(), self::POST_SIGNALS, 'value');
    }

    public function testPostEnforceOutputObligationFailureThrows(): void
    {
        $decision = new AuthorizationDecision(Decision::PERMIT, [['type' => 'boom']]);
        $provider = new FakeConstraintHandlerProvider('boom', [
            new ScopedHandler(new Runner(static fn () => throw new RuntimeException('x')), SignalKind::OUTPUT, 0),
        ]);

        $this->expectException(AccessDeniedException::class);

        $this->pep($decision, $provider)->postEnforce($this->subscription(), self::POST_SIGNALS, 'value');
    }

    public function testPostEnforceDecisionObligationFailureThreadsIntoOutput(): void
    {
        $decision = new AuthorizationDecision(Decision::PERMIT, [['type' => 'boom']]);
        $provider = new FakeConstraintHandlerProvider('boom', [
            new ScopedHandler(new Runner(static fn () => throw new RuntimeException('x')), SignalKind::DECISION, 0),
        ]);

        $this->expectException(AccessDeniedException::class);

        $this->pep($decision, $provider)->postEnforce($this->subscription(), self::POST_SIGNALS, 'value');
    }

    private function pep(AuthorizationDecision $decision, FakeConstraintHandlerProvider ...$providers): BlockingPolicyEnforcementPoint
    {
        return new BlockingPolicyEnforcementPoint(
            new FakePolicyDecisionPoint($decision),
            new EnforcementPlanner(array_values($providers)),
        );
    }

    private function subscription(): AuthorizationSubscription
    {
        return new AuthorizationSubscription(subject: 'alice', action: 'read', resource: 'doc');
    }

    /**
     * @param list<mixed>                  $arguments
     * @param callable(list<mixed>): mixed $proceed
     */
    private function invocation(array $arguments, callable $proceed): MethodInvocation
    {
        return new MethodInvocation($arguments, $proceed(...));
    }

    /**
     * @param callable(): mixed $action
     */
    private function capture(callable $action): Throwable
    {
        try {
            $action();
        } catch (Throwable $thrown) {
            return $thrown;
        }

        self::fail('Expected an exception to be thrown.');
    }
}
