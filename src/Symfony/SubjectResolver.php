<?php

declare(strict_types=1);

namespace Sapl\Symfony;

/**
 * Supplies the authorization subject for the current request (typically the
 * authenticated user's identifier), used when an enforcement attribute does not
 * set a subject explicitly.
 */
interface SubjectResolver
{
    public function currentSubject(): mixed;
}
