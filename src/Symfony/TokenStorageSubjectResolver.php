<?php

declare(strict_types=1);

namespace Sapl\Symfony;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Resolves the subject from the Symfony Security token: the authenticated user's
 * identifier, or null when there is no authenticated user.
 */
final class TokenStorageSubjectResolver implements SubjectResolver
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function currentSubject(): mixed
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user?->getUserIdentifier();
    }
}
