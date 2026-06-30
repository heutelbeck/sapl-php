<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Pep\Constraints;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Sapl\Api\AuthorizationDecision;
use Sapl\Api\Decision;
use Sapl\Pep\Constraints\ConstraintHandlerProvider;
use Sapl\Pep\Constraints\EnforcementPlan;
use Sapl\Pep\Constraints\EnforcementPlanner;
use Sapl\Pep\Constraints\Runner;
use Sapl\Pep\Constraints\ScopedHandler;
use Sapl\Pep\Constraints\SignalKind;
use Sapl\Pep\Present;
use Sapl\Tests\Fake\FakeConstraintHandlerProvider;

/**
 * A constraint handler provider that fails while resolving a handler models the
 * real-world case of a malformed constraint that the provider cannot parse. Per
 * the Spring PEP, such a failure is caught and treated as a no-claim, so the
 * affected constraint falls through to the fail-closed substitute path while the
 * rest of the decision is still enforced. These scenarios pin that behaviour.
 *
 * Traceability: PLAN-PROVIDER-THROW.
 */
final class EnforcementPlannerProviderFailureTest extends TestCase
{
    /** @var list<SignalKind> */
    private const array SUPPORTED = [
        SignalKind::DECISION,
        SignalKind::INPUT,
        SignalKind::OUTPUT,
        SignalKind::ERROR,
        SignalKind::COMPLETE,
    ];

    public function testPlanningSurvivesAProviderThatThrowsWhileResolvingAConstraint(): void
    {
        $decision = new AuthorizationDecision(Decision::PERMIT, [['type' => 'malformed']]);

        $plan = (new EnforcementPlanner([$this->throwingProvider('malformed')]))->plan($decision, self::SUPPORTED);

        self::assertInstanceOf(EnforcementPlan::class, $plan);
    }

    public function testObligationWhoseSoleProviderThrowsFailsClosed(): void
    {
        $decision = new AuthorizationDecision(Decision::PERMIT, [['type' => 'malformed']]);

        $plan = (new EnforcementPlanner([$this->throwingProvider('malformed')]))->plan($decision, self::SUPPORTED);

        self::assertTrue($plan->execute(SignalKind::DECISION, new Present('d'), false)->failureState);
    }

    public function testValidObligationIsStillEnforcedWhenAnotherProviderThrows(): void
    {
        $logRan       = false;
        $logObligation = new FakeConstraintHandlerProvider('log', [
            new ScopedHandler(new Runner(static function () use (&$logRan): void {
                $logRan = true;
            }), SignalKind::DECISION, 0),
        ]);
        $decision = new AuthorizationDecision(Decision::PERMIT, [['type' => 'malformed'], ['type' => 'log']]);

        $plan   = (new EnforcementPlanner([$this->throwingProvider('malformed'), $logObligation]))->plan($decision, self::SUPPORTED);
        $result = $plan->execute(SignalKind::DECISION, new Present('d'), false);

        self::assertTrue($result->failureState);
        self::assertTrue($logRan);
    }

    public function testAdviceWhoseProviderThrowsCompletesSilently(): void
    {
        $decision = new AuthorizationDecision(Decision::PERMIT, [], [['type' => 'malformed']]);

        $plan = (new EnforcementPlanner([$this->throwingProvider('malformed')]))->plan($decision, self::SUPPORTED);

        self::assertFalse($plan->execute(SignalKind::DECISION, new Present('d'), false)->failureState);
    }

    private function throwingProvider(string $type): ConstraintHandlerProvider
    {
        return new class($type) implements ConstraintHandlerProvider {
            public function __construct(private readonly string $type)
            {
            }

            public function getConstraintHandlers(mixed $constraint, array $supportedSignals): array
            {
                if (is_array($constraint) && ($constraint['type'] ?? null) === $this->type) {
                    throw new RuntimeException('cannot parse malformed constraint');
                }

                return [];
            }
        };
    }
}
