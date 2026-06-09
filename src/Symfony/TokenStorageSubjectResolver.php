<?php

declare(strict_types=1);

namespace Sapl\Symfony;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Resolves the subject from the Symfony Security token: a structured object with
 * the authenticated user's identifier and roles, or "anonymous" when there is no
 * authenticated user. Credentials are never included.
 */
final class TokenStorageSubjectResolver implements SubjectResolver
{
    private const string ANONYMOUS = 'anonymous';

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function currentSubject(): mixed
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if (null === $user) {
            return self::ANONYMOUS;
        }

        return [
            'username' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
        ];
    }
}
