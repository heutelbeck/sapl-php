<?php

declare(strict_types=1);

namespace Sapl\Tests\Integration\Doctrine;

use Doctrine\ODM\MongoDB\Mapping\Annotations\Document;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Field;
use Doctrine\ODM\MongoDB\Mapping\Annotations\Id;

#[Document(collection: 'patient_document')]
class PatientDocument
{
    public function __construct(
        #[Id(strategy: 'NONE', type: 'string')]
        public string $id,
        #[Field(type: 'int')]
        public int $tenantId,
        #[Field(type: 'string')]
        public string $name,
    ) {
    }
}
