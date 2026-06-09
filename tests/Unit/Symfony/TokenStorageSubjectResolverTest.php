<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Symfony;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sapl\Symfony\TokenStorageSubjectResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class TokenStorageSubjectResolverTest extends TestCase
{
    #[Test]
    public function whenAuthenticatedThenSubjectCarriesIdentifierAndRoles(): void
    {
        $user = new InMemoryUser('alice', 'secret', ['ROLE_DOCTOR', 'ROLE_USER']);
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $subject = (new TokenStorageSubjectResolver($storage))->currentSubject();

        self::assertSame(['username' => 'alice', 'roles' => ['ROLE_DOCTOR', 'ROLE_USER']], $subject);
    }

    #[Test]
    public function whenAuthenticatedThenSubjectNeverContainsCredentials(): void
    {
        $user = new InMemoryUser('bob', 'topsecret', ['ROLE_USER']);
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $subject = (new TokenStorageSubjectResolver($storage))->currentSubject();

        self::assertIsArray($subject);
        self::assertArrayNotHasKey('password', $subject);
        self::assertNotContains('topsecret', $subject);
    }

    #[Test]
    public function whenNoTokenThenSubjectIsAnonymous(): void
    {
        $subject = (new TokenStorageSubjectResolver(new TokenStorage()))->currentSubject();

        self::assertSame('anonymous', $subject);
    }
}
