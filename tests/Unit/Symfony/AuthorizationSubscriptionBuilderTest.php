<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Symfony;

use PHPUnit\Framework\TestCase;
use Sapl\Symfony\AuthorizationSubscriptionBuilder;
use Sapl\Symfony\PostEnforce;
use Sapl\Symfony\PreEnforce;
use Sapl\Symfony\SubjectResolver;

final class AuthorizationSubscriptionBuilderTest extends TestCase
{
    public function testInvocationUsesAttributeFieldsWhenSet(): void
    {
        $subscription = $this->builder('alice')->forInvocation(
            new PreEnforce(action: 'readPatients', resource: 'patients'),
            'App\\Controller\\PatientController',
            'list',
        );

        self::assertSame('alice', $subscription->subject);
        self::assertSame('readPatients', $subscription->action);
        self::assertSame('patients', $subscription->resource);
    }

    public function testInvocationDerivesDefaultsFromContext(): void
    {
        $subscription = $this->builder('bob')->forInvocation(
            new PreEnforce(),
            'App\\Controller\\PatientController',
            'list',
        );

        self::assertSame('bob', $subscription->subject);
        self::assertSame('PatientController.list', $subscription->action);
        self::assertSame('PatientController', $subscription->resource);
    }

    public function testResultUsesReturnValueAsResourceByDefault(): void
    {
        $patient = ['id' => 'P-1', 'ssn' => '123'];
        $subscription = $this->builder('carol')->forResult(
            new PostEnforce(action: 'readPatient'),
            'App\\Controller\\PatientController',
            'one',
            $patient,
        );

        self::assertSame('readPatient', $subscription->action);
        self::assertSame($patient, $subscription->resource);
    }

    public function testExplicitSubjectOverridesResolver(): void
    {
        $subscription = $this->builder('resolved')->forInvocation(
            new PreEnforce(subject: 'explicit'),
            'App\\Service\\Thing',
            'run',
        );

        self::assertSame('explicit', $subscription->subject);
    }

    private function builder(mixed $subject): AuthorizationSubscriptionBuilder
    {
        $resolver = new class($subject) implements SubjectResolver {
            public function __construct(private readonly mixed $subject)
            {
            }

            public function currentSubject(): mixed
            {
                return $this->subject;
            }
        };

        return new AuthorizationSubscriptionBuilder($resolver);
    }
}
