<?php

declare(strict_types=1);

namespace Sapl\Pep\Constraints;

/**
 * Whether a constraint is binding (obligation) or best-effort (advice).
 */
enum ConstraintType
{
    case OBLIGATION;
    case ADVICE;
}
