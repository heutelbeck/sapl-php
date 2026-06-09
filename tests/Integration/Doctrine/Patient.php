<?php

declare(strict_types=1);

namespace Sapl\Tests\Integration\Doctrine;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

#[Entity]
#[Table(name: 'patient')]
class Patient
{
    public function __construct(
        #[Id]
        #[Column(type: 'integer')]
        public int $id,
        #[Column(name: 'tenant_id', type: 'integer')]
        public int $tenantId,
        #[Column(type: 'string')]
        public string $name,
    ) {
    }
}
