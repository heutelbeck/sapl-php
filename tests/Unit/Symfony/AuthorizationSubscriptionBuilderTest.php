<?php

declare(strict_types=1);

namespace Sapl\Tests\Unit\Symfony;

use PHPUnit\Framework\TestCase;
use Sapl\Symfony\AuthorizationSubscriptionBuilder;
use Sapl\Symfony\PostEnforce;
use Sapl\Symfony\PreEnforce;
use Sapl\Symfony\SubjectResolver;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuthorizationSubscriptionBuilderTest extends TestCase
{
    public function testInvocationUsesAttributeLiteralsWhenSet(): void
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

    public function testInvocationDerivesFlatDefaultsWithoutRequest(): void
    {
        $subscription = $this->builder('bob')->forInvocation(
            new PreEnforce(),
            'App\\Controller\\PatientController',
            'list',
        );

        self::assertSame('bob', $subscription->subject);
        self::assertSame(['controller' => 'PatientController', 'handler' => 'list'], $subscription->action);
        self::assertSame(['path' => '', 'params' => []], $subscription->resource);
    }

    public function testInvocationFoldsHttpRequestDataIntoDefaults(): void
    {
        $request = Request::create('/api/patient/P-1', 'GET');
        $request->attributes->set('_route_params', ['id' => 'P-1']);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $subscription = $this->builder('bob', $requestStack)->forInvocation(
            new PreEnforce(),
            'App\\Controller\\PatientController',
            'one',
        );

        self::assertSame(
            ['method' => 'GET', 'controller' => 'PatientController', 'handler' => 'one'],
            $subscription->action,
        );
        self::assertSame(['path' => '/api/patient/P-1', 'params' => ['id' => 'P-1']], $subscription->resource);
    }

    public function testInvocationEvaluatesExpressionAgainstArgs(): void
    {
        $subscription = $this->builder('carol')->forInvocation(
            new PreEnforce(action: 'readPatient', resource: new Expression("{ type: 'patient', id: args['id'] }")),
            'App\\Controller\\PatientController',
            'one',
            ['id' => 'P-1'],
        );

        self::assertSame('readPatient', $subscription->action);
        self::assertSame(['type' => 'patient', 'id' => 'P-1'], $subscription->resource);
    }

    public function testResultDerivesFlatResourceDefaultNotReturnValue(): void
    {
        $patient = ['id' => 'P-1', 'ssn' => '123'];
        $subscription = $this->builder('dave')->forResult(
            new PostEnforce(action: 'readPatient'),
            'App\\Controller\\PatientController',
            'one',
            $patient,
        );

        self::assertSame(['path' => '', 'params' => []], $subscription->resource);
    }

    public function testResultExpressionCanReadReturnValue(): void
    {
        $subscription = $this->builder('erin')->forResult(
            new PostEnforce(resource: new Expression("returnValue['id']")),
            'App\\Controller\\PatientController',
            'one',
            ['id' => 'P-9', 'ssn' => '000'],
        );

        self::assertSame('P-9', $subscription->resource);
    }

    public function testSecretsFieldIsCarried(): void
    {
        $subscription = $this->builder('frank')->forInvocation(
            new PreEnforce(action: 'read', secrets: ['apiKey' => 'k']),
            'App\\Service\\Thing',
            'run',
        );

        self::assertSame(['apiKey' => 'k'], $subscription->secrets);
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

    private function builder(mixed $subject, ?RequestStack $requestStack = null): AuthorizationSubscriptionBuilder
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

        return new AuthorizationSubscriptionBuilder($resolver, new ExpressionLanguage(), $requestStack);
    }
}
