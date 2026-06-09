<?php

declare(strict_types=1);

namespace Sapl\Pep;

/**
 * A present value.
 */
final class Present implements Maybe
{
    public function __construct(
        public readonly mixed $value,
    ) {
    }
}
