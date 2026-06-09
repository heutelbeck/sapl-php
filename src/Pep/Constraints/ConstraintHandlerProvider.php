<?php

declare(strict_types=1);

namespace Sapl\Pep\Constraints;

/**
 * Translates one constraint into the handlers that enforce it.
 *
 * Returns an empty list when the provider does not recognise the constraint,
 * otherwise one or more scoped handlers. Exactly one provider may claim a
 * constraint; the planner schedules each returned handler against its signal
 * independently, so one obligation can drive several handlers across lifecycle
 * points. See {@see ConstraintGuards} for the dispatch helpers.
 */
interface ConstraintHandlerProvider
{
    /**
     * @param mixed            $constraint       the decoded obligation or advice value
     * @param list<SignalKind> $supportedSignals the signals the deployed PEP fires
     *
     * @return list<ScopedHandler> the handlers that enforce the constraint, or an empty list
     */
    public function getConstraintHandlers(mixed $constraint, array $supportedSignals): array;
}
