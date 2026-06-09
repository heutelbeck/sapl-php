<?php

declare(strict_types=1);

namespace Sapl\Pep\Constraints;

/**
 * The lifecycle points at which constraint handlers attach.
 *
 * Decision, Input, Output, and Error carry a value (mappers and consumers may
 * attach); Cancel, Complete, and Termination do not (runners only). The shim
 * data signals (e.g. SQL_QUERY) are registered separately and are value-carrying.
 *
 * Adapted from the reference: PHP is dynamically typed, so the output signal is a
 * single kind rather than being discriminated by element type (no Flux<T>).
 */
enum SignalKind: string
{
    case DECISION = 'decision';
    case INPUT = 'input';
    case OUTPUT = 'output';
    case ERROR = 'error';
    case CANCEL = 'cancel';
    case COMPLETE = 'complete';
    case TERMINATION = 'termination';
    case SQL_QUERY = 'sql_query';
    case MONGO_QUERY = 'mongo_query';

    /**
     * True when handlers attached here receive a value (mappers/consumers allowed).
     */
    public function isValueCarrying(): bool
    {
        return match ($this) {
            self::DECISION, self::INPUT, self::OUTPUT, self::ERROR, self::SQL_QUERY, self::MONGO_QUERY => true,
            self::CANCEL, self::COMPLETE, self::TERMINATION => false,
        };
    }
}
