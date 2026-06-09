<?php

declare(strict_types=1);

namespace Sapl\Pep\Streaming;

/**
 * The codomain of the machine's step function: the post-step {@see State} paired
 * with the ordered emissions produced by the step. An empty emission list means
 * "event processed, nothing to emit downstream".
 */
final class StepResult
{
    /**
     * @param list<Emission> $emissions
     */
    public function __construct(
        public readonly State $state,
        public readonly array $emissions,
    ) {
    }

    public static function to(State $state, Emission ...$emissions): self
    {
        return new self($state, array_values($emissions));
    }

    public function isTerminal(): bool
    {
        return $this->state instanceof Terminated;
    }
}
