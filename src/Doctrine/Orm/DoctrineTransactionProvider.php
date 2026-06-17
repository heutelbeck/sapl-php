<?php

declare(strict_types=1);

namespace Sapl\Doctrine\Orm;

use Doctrine\ORM\EntityManagerInterface;
use Sapl\Pep\TransactionProvider;

/**
 * A {@see TransactionProvider} backed by a Doctrine ORM EntityManager.
 *
 * The enforced invocation runs inside {@see EntityManagerInterface::wrapInTransaction()},
 * which flushes and commits when the callable returns cleanly and, when the
 * callable throws, rolls the transaction back, closes the EntityManager, and
 * re-throws. The re-thrown exception (an AccessDeniedException on an enforcement
 * denial) propagates out so the denied request fails after its writes are undone.
 *
 * The documented post-rollback caveat, that the EntityManager is closed and its
 * managed entities detached, is acceptable here: a rollback only happens on a
 * denial, and the denied request ends as a 403 rather than continuing to use the
 * EntityManager.
 */
final class DoctrineTransactionProvider implements TransactionProvider
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function transactional(callable $work): mixed
    {
        return $this->entityManager->wrapInTransaction(static fn (): mixed => $work());
    }
}
