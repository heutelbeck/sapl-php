<?php

declare(strict_types=1);

namespace Sapl\Pep;

/**
 * A two-case optional: a value is either {@see Present} or {@see Absent}.
 *
 * Used to thread a constraint-handler value through a signal discharge, where a
 * mapper may legitimately drop the value (distinct from a present null).
 */
interface Maybe
{
}
