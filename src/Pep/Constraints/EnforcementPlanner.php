<?php

declare(strict_types=1);

namespace Sapl\Pep\Constraints;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Sapl\Api\AuthorizationDecision;
use Sapl\Pep\AccessDeniedException;

/**
 * Builds the enforcement plan P(d) for an authorization decision.
 *
 * Phase 1 resolves a handler for each obligation and advice via the registered
 * providers, substituting a failure runner when resolution is unresolved,
 * ambiguous, or inadmissible. Phase 2 sorts each per-signal sequence and replaces
 * any same-priority mapper group of length greater than one with failure runners,
 * since mapper-composition commutativity cannot be proven. A decision that carries
 * a resource adds an implicit obligation mapper at the output signal substituting
 * the resource for the output.
 */
final class EnforcementPlanner
{
    private const int SUBSTITUTE_PRIORITY = 0;

    /** @var list<ConstraintHandlerProvider> */
    private readonly array $providers;

    private readonly LoggerInterface $logger;

    /**
     * @param iterable<ConstraintHandlerProvider> $providers
     */
    public function __construct(iterable $providers, ?LoggerInterface $logger = null)
    {
        $this->providers = is_array($providers) ? array_values($providers) : iterator_to_array($providers, false);
        $this->logger    = $logger ?? new NullLogger();
    }

    /**
     * @param list<SignalKind> $supportedSignals the signals the deployed PEP fires
     */
    public function plan(AuthorizationDecision $decision, array $supportedSignals): EnforcementPlan
    {
        /** @var array<string, list<EnforcementPlanEntry>> $entriesBySignal */
        $entriesBySignal = [];
        $this->scheduleHandlers($decision->obligations, ConstraintType::OBLIGATION, $supportedSignals, $entriesBySignal);
        $this->scheduleHandlers($decision->advice, ConstraintType::ADVICE, $supportedSignals, $entriesBySignal);
        $this->addImplicitResourceObligation($decision, $supportedSignals, $entriesBySignal);
        $this->sortAndEnforceCommutativity($entriesBySignal);

        return new EnforcementPlan($entriesBySignal);
    }

    /**
     * @param list<mixed>                               $constraints
     * @param list<SignalKind>                          $supportedSignals
     * @param array<string, list<EnforcementPlanEntry>> &$entriesBySignal
     */
    private function scheduleHandlers(
        array $constraints,
        ConstraintType $constraintType,
        array $supportedSignals,
        array &$entriesBySignal,
    ): void {
        foreach ($constraints as $constraint) {
            foreach ($this->assignHandlers($constraint, $constraintType, $supportedSignals) as [$signal, $entry]) {
                $this->scheduleAt($entriesBySignal, $signal, $entry);
            }
        }
    }

    /**
     * Exactly one provider must claim a constraint. A claim may carry several
     * handlers, each scoped to its own signal; any inadmissible handler fails the
     * claim.
     *
     * @param list<SignalKind> $supportedSignals
     *
     * @return list<array{SignalKind, EnforcementPlanEntry}>
     */
    private function assignHandlers(mixed $constraint, ConstraintType $constraintType, array $supportedSignals): array
    {
        $claims = [];
        foreach ($this->providers as $provider) {
            $claim = $this->claimHandlers($provider, $constraint, $supportedSignals);
            if ([] !== $claim) {
                $claims[] = $claim;
            }
        }

        if ([] === $claims) {
            return [$this->failureSubstitute($constraint, $constraintType, 'unresolved')];
        }
        if (count($claims) > 1) {
            return [$this->failureSubstitute($constraint, $constraintType, 'ambiguous')];
        }

        $scopedHandlers = $claims[0];
        foreach ($scopedHandlers as $scoped) {
            if (!$this->isAdmissible($scoped, $constraintType, $supportedSignals)) {
                return [$this->failureSubstitute($constraint, $constraintType, 'inadmissible')];
            }
        }

        $assignments = [];
        foreach ($scopedHandlers as $scoped) {
            $assignments[] = [
                $scoped->signal,
                new EnforcementPlanEntry($scoped->handler, $scoped->priority, $constraintType, $constraint),
            ];
        }

        return $assignments;
    }

    /**
     * A throwing provider is treated as a no-claim, so a malformed constraint the
     * provider cannot parse fails closed via the synthetic substitute instead of
     * escaping plan() as a raw exception.
     *
     * @param list<SignalKind> $supportedSignals
     *
     * @return list<ScopedHandler>
     */
    private function claimHandlers(ConstraintHandlerProvider $provider, mixed $constraint, array $supportedSignals): array
    {
        try {
            return $provider->getConstraintHandlers($constraint, $supportedSignals);
        } catch (RuntimeException $exception) {
            $this->logger->warning(
                'Constraint handler provider {provider} failed to resolve a handler; treating as unresolved: {message}',
                ['provider' => $provider::class, 'message' => $exception->getMessage()],
            );

            return [];
        }
    }

    /**
     * Admissible when the signal is supported, advice carries no mapper, and
     * mappers/consumers attach only to value-carrying signals while runners attach
     * anywhere.
     *
     * @param list<SignalKind> $supportedSignals
     */
    private function isAdmissible(ScopedHandler $scoped, ConstraintType $constraintType, array $supportedSignals): bool
    {
        if (!ConstraintGuards::supports($supportedSignals, $scoped->signal)) {
            return false;
        }
        if ($scoped->handler instanceof Mapper && ConstraintType::OBLIGATION !== $constraintType) {
            return false;
        }

        return $scoped->signal->isValueCarrying() || $scoped->handler instanceof Runner;
    }

    /**
     * @param list<SignalKind>                          $supportedSignals
     * @param array<string, list<EnforcementPlanEntry>> &$entriesBySignal
     */
    private function addImplicitResourceObligation(
        AuthorizationDecision $decision,
        array $supportedSignals,
        array &$entriesBySignal,
    ): void {
        if (!$decision->hasResource) {
            return;
        }
        $resource = $decision->resource;
        if (!ConstraintGuards::supports($supportedSignals, SignalKind::OUTPUT)) {
            [$signal, $entry] = $this->failureSubstitute($resource, ConstraintType::OBLIGATION, 'inadmissible');
            $this->scheduleAt($entriesBySignal, $signal, $entry);

            return;
        }

        $mapper = new Mapper(static fn (mixed $output): mixed => $resource);
        $this->scheduleAt(
            $entriesBySignal,
            SignalKind::OUTPUT,
            new EnforcementPlanEntry($mapper, PHP_INT_MIN, ConstraintType::OBLIGATION, $resource),
        );
    }

    /**
     * @param array<string, list<EnforcementPlanEntry>> &$entriesBySignal
     */
    private function sortAndEnforceCommutativity(array &$entriesBySignal): void
    {
        foreach ($entriesBySignal as $key => $entries) {
            usort(
                $entries,
                static fn (EnforcementPlanEntry $a, EnforcementPlanEntry $b): int => [$a->priority, $a->shapeRank()] <=> [$b->priority, $b->shapeRank()],
            );
            $entriesBySignal[$key] = $this->replaceNonCommutingMapperGroups($entries);
        }
    }

    /**
     * Any maximal run of mappers at equal priority of length greater than one is
     * replaced by failure runners, since the planner cannot prove the composition
     * commutes.
     *
     * @param list<EnforcementPlanEntry> $entries
     *
     * @return list<EnforcementPlanEntry>
     */
    private function replaceNonCommutingMapperGroups(array $entries): array
    {
        $index = 0;
        $count = count($entries);
        while ($index < $count) {
            if (!$entries[$index]->handler instanceof Mapper) {
                ++$index;

                continue;
            }
            $groupPriority = $entries[$index]->priority;
            $groupEnd = $index;
            while ($groupEnd + 1 < $count
                && $entries[$groupEnd + 1]->handler instanceof Mapper
                && $entries[$groupEnd + 1]->priority === $groupPriority) {
                ++$groupEnd;
            }
            if ($groupEnd > $index) {
                for ($i = $index; $i <= $groupEnd; ++$i) {
                    $original = $entries[$i];
                    $entries[$i] = new EnforcementPlanEntry(
                        $this->syntheticFailureRunner($original->constraint, $original->constraintType, 'non-commuting group'),
                        $original->priority,
                        $original->constraintType,
                        $original->constraint,
                    );
                }
            }
            $index = $groupEnd + 1;
        }

        return array_values($entries);
    }

    /**
     * @return array{SignalKind, EnforcementPlanEntry}
     */
    private function failureSubstitute(mixed $constraint, ConstraintType $constraintType, string $reason): array
    {
        return [
            SignalKind::DECISION,
            new EnforcementPlanEntry(
                $this->syntheticFailureRunner($constraint, $constraintType, $reason),
                self::SUBSTITUTE_PRIORITY,
                $constraintType,
                $constraint,
            ),
        ];
    }

    /**
     * On invocation, an obligation substitute denies; an advice substitute completes.
     */
    private function syntheticFailureRunner(mixed $constraint, ConstraintType $constraintType, string $reason): Runner
    {
        return new Runner(static function () use ($constraint, $constraintType, $reason): void {
            if (ConstraintType::OBLIGATION === $constraintType) {
                $json = json_encode($constraint);
                $text = false === $json ? '<unencodable constraint>' : $json;

                throw new AccessDeniedException(sprintf('Unhandled obligation (%s): %s', $reason, $text));
            }
        });
    }

    /**
     * @param array<string, list<EnforcementPlanEntry>> &$entriesBySignal
     */
    private function scheduleAt(array &$entriesBySignal, SignalKind $signal, EnforcementPlanEntry $entry): void
    {
        $entriesBySignal[$signal->value][] = $entry;
    }
}
