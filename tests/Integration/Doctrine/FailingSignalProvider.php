<?php

declare(strict_types=1);

namespace Sapl\Tests\Integration\Doctrine;

use RuntimeException;
use Sapl\Pep\Constraints\ConstraintGuards;
use Sapl\Pep\Constraints\ConstraintHandlerProvider;
use Sapl\Pep\Constraints\Runner;
use Sapl\Pep\Constraints\ScopedHandler;
use Sapl\Pep\Constraints\SignalKind;

/**
 * Test constraint handler provider that claims a `test:failAt` obligation and
 * attaches a Runner that always throws at the obligation's named signal.
 *
 * It gives the transaction tests deterministic control over which enforcement
 * stage fails. A failure at OUTPUT models an output-obligation discharge failure
 * raised after the protected method has written; a failure at DECISION models a
 * decision-stage obligation discharge failure. The plan fails closed on the
 * throwing obligation handler, the PEP denies, and the transaction boundary rolls
 * back the method's writes.
 */
final class FailingSignalProvider implements ConstraintHandlerProvider
{
    private const string CONSTRAINT_TYPE = 'test:failAt';

    public function getConstraintHandlers(mixed $constraint, array $supportedSignals): array
    {
        if (!ConstraintGuards::isOfType($constraint, self::CONSTRAINT_TYPE)) {
            return [];
        }
        $signalName = ConstraintGuards::stringField($constraint, 'signal');
        if (null === $signalName) {
            return [];
        }

        return [
            new ScopedHandler(
                new Runner(static function (): void {
                    throw new RuntimeException('forced obligation failure');
                }),
                SignalKind::from($signalName),
                0,
            ),
        ];
    }
}
