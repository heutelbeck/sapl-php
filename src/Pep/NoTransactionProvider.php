<?php

declare(strict_types=1);

namespace Sapl\Pep;

/**
 * The default transaction provider, used when no opt-in boundary is configured.
 *
 * It runs the callable directly with no transaction, so an application that has
 * not configured a persistence-backed provider sees exactly the unenforced
 * behavior: no commit, no rollback, no boundary.
 */
final class NoTransactionProvider implements TransactionProvider
{
    public function transactional(callable $work): mixed
    {
        return $work();
    }
}
