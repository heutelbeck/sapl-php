<?php

declare(strict_types=1);

namespace Sapl\Pep;

/**
 * Runs an enforced invocation inside a transaction boundary so that an
 * enforcement failure raised after the protected method has written its changes
 * rolls those changes back.
 *
 * The boundary commits when the callable returns cleanly and rolls back when the
 * callable throws, letting the thrown exception (an {@see AccessDeniedException}
 * on a denial) propagate out unchanged. The PEP is persistence-agnostic: a
 * persistence-specific implementation supplies the actual boundary, and the
 * default {@see NoTransactionProvider} runs the callable directly so that an
 * application without an opt-in provider sees unchanged behavior.
 */
interface TransactionProvider
{
    /**
     * @template T
     *
     * @param callable(): T $work the enforced invocation to run transactionally
     *
     * @return T the value returned by the callable
     */
    public function transactional(callable $work): mixed;
}
