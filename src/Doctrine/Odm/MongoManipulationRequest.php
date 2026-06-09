<?php

declare(strict_types=1);

namespace Sapl\Doctrine\Odm;

/**
 * The value the Mongo shim discharges through the enforcement plan when a
 * Doctrine ODM filter fires.
 *
 * Doctrine ODM AND-merges the criteria a filter returns across the documents a
 * query touches, so the filter contributes a narrowing criteria array rather
 * than rewriting a query. The request carries the document class the filter is
 * scoping, for provider context; no alias is needed because BSON criteria merge
 * by field name.
 */
final class MongoManipulationRequest
{
    /**
     * @param class-string $documentClass
     */
    public function __construct(
        public readonly string $documentClass,
    ) {
    }
}
