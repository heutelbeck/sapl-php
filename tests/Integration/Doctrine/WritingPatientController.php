<?php

declare(strict_types=1);

namespace Sapl\Tests\Integration\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Sapl\Symfony\PostEnforce;
use Sapl\Symfony\PreEnforce;

/**
 * A controller whose actions persist a Patient and flush, so a transaction test
 * can prove that an enforcement denial after the write rolls the row back. The
 * methods carry real enforcement attributes so the controller subscriber resolves
 * them by reflection exactly as it does in production.
 */
final class WritingPatientController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[PreEnforce(action: 'create', resource: 'patient')]
    public function createPre(): string
    {
        return $this->write();
    }

    #[PostEnforce(action: 'create', resource: 'patient')]
    public function createPost(): string
    {
        return $this->write();
    }

    private function write(): string
    {
        $this->entityManager->persist(new Patient(1, 1, 'Alice'));
        $this->entityManager->flush();

        return 'Alice';
    }
}
