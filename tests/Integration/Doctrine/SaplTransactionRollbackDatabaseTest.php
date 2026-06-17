<?php

declare(strict_types=1);

namespace Sapl\Tests\Integration\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Sapl\Api\AuthorizationDecision;
use Sapl\Api\Decision;
use Sapl\Doctrine\Orm\DoctrineTransactionProvider;
use Sapl\Pep\AccessDeniedException;
use Sapl\Pep\BlockingPolicyEnforcementPoint;
use Sapl\Pep\Constraints\EnforcementPlanner;
use Sapl\Pep\Constraints\Providers\ContentFilteringProvider;
use Sapl\Pep\Streaming\StreamingPolicyEnforcementPoint;
use Sapl\Symfony\AuthorizationSubscriptionBuilder;
use Sapl\Symfony\EnforcementControllerSubscriber;
use Sapl\Symfony\EnforcementSignals;
use Sapl\Symfony\Proxy\SaplInterceptor;
use Sapl\Symfony\StreamEnforcer;
use Sapl\Symfony\SubjectResolver;
use Sapl\Tests\Fake\FakePolicyDecisionPoint;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Proves the opt-in Doctrine transaction boundary against a real in-memory SQLite
 * database, for both interception layers (the controller subscriber and the
 * service proxy interceptor).
 *
 * A protected method writes a Patient row and flushes. When enforcement permits,
 * the row is committed. When enforcement denies after the method has written, the
 * boundary rolls the row back: a PreEnforce output-obligation failure, a
 * PostEnforce non-PERMIT decision, a PostEnforce decision-stage obligation
 * failure, and a PostEnforce output-obligation failure each undo the write. Each
 * assertion is on the real committed row count, read through the surviving
 * connection (the EntityManager is closed by the rollback).
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class SaplTransactionRollbackDatabaseTest extends TestCase
{
    private const string CONTROLLER = 'controller subscriber';
    private const string PROXY = 'service proxy';

    private Connection $connection;
    private EntityManagerInterface $em;
    private AuthorizationSubscriptionBuilder $builder;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([__DIR__]);
        $config->enableNativeLazyObjects(true);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->em = new EntityManager($this->connection, $config);

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->createSchema([$this->em->getClassMetadata(Patient::class)]);

        $resolver = new class implements SubjectResolver {
            public function currentSubject(): mixed
            {
                return 'tester';
            }
        };
        $this->builder = new AuthorizationSubscriptionBuilder($resolver, new ExpressionLanguage());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function interceptionLayers(): iterable
    {
        yield self::CONTROLLER => [self::CONTROLLER];
        yield self::PROXY => [self::PROXY];
    }

    #[DataProvider('interceptionLayers')]
    public function testPermitCommitsThePreEnforceWrite(string $layer): void
    {
        $this->invokePre($layer, AuthorizationDecision::permit());

        self::assertSame(1, $this->committedRowCount());
    }

    #[DataProvider('interceptionLayers')]
    public function testPermitCommitsThePostEnforceWrite(string $layer): void
    {
        $this->invokePost($layer, AuthorizationDecision::permit());

        self::assertSame(1, $this->committedRowCount());
    }

    #[DataProvider('interceptionLayers')]
    public function testPreEnforceOutputObligationFailureRollsBackTheWrite(string $layer): void
    {
        $decision = $this->permitWith(['type' => 'test:failAt', 'signal' => 'output']);

        $this->expectDeniedThenAssertEmpty(fn (): mixed => $this->invokePre($layer, $decision));
    }

    #[DataProvider('interceptionLayers')]
    public function testPostEnforceNonPermitDecisionRollsBackTheWrite(string $layer): void
    {
        $this->expectDeniedThenAssertEmpty(fn (): mixed => $this->invokePost($layer, AuthorizationDecision::deny()));
    }

    #[DataProvider('interceptionLayers')]
    public function testPostEnforceDecisionStageObligationFailureRollsBackTheWrite(string $layer): void
    {
        $decision = $this->permitWith(['type' => 'test:failAt', 'signal' => 'decision']);

        $this->expectDeniedThenAssertEmpty(fn (): mixed => $this->invokePost($layer, $decision));
    }

    #[DataProvider('interceptionLayers')]
    public function testPostEnforceOutputObligationFailureRollsBackTheWrite(string $layer): void
    {
        $decision = $this->permitWith(['type' => 'test:failAt', 'signal' => 'output']);

        $this->expectDeniedThenAssertEmpty(fn (): mixed => $this->invokePost($layer, $decision));
    }

    private function expectDeniedThenAssertEmpty(callable $invocation): void
    {
        try {
            $invocation();
            self::fail('Expected an AccessDeniedException.');
        } catch (AccessDeniedException) {
            self::assertSame(0, $this->committedRowCount());
        }
    }

    private function invokePre(string $layer, AuthorizationDecision $decision): mixed
    {
        $controller = new WritingPatientController($this->em);
        if (self::CONTROLLER === $layer) {
            return $this->dispatchController($decision, [$controller, 'createPre']);
        }

        return $this->interceptor($decision)->enforce(
            WritingPatientController::class,
            'createPre',
            [],
            static fn (): mixed => $controller->createPre(),
        );
    }

    private function invokePost(string $layer, AuthorizationDecision $decision): mixed
    {
        $controller = new WritingPatientController($this->em);
        if (self::CONTROLLER === $layer) {
            return $this->dispatchController($decision, [$controller, 'createPost']);
        }

        return $this->interceptor($decision)->enforce(
            WritingPatientController::class,
            'createPost',
            [],
            static fn (): mixed => $controller->createPost(),
        );
    }

    private function dispatchController(AuthorizationDecision $decision, callable $controller): mixed
    {
        $subscriber = new EnforcementControllerSubscriber(
            $this->pepFor($decision),
            $this->builder,
            $this->streamEnforcer(),
            new DoctrineTransactionProvider($this->em),
        );
        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new ControllerArgumentsEvent($kernel, $controller, [], new Request(), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onControllerArguments($event);

        return ($event->getController())();
    }

    private function interceptor(AuthorizationDecision $decision): SaplInterceptor
    {
        return new SaplInterceptor(
            $this->pepFor($decision),
            $this->builder,
            $this->streamEnforcer(),
            new DoctrineTransactionProvider($this->em),
        );
    }

    private function pepFor(AuthorizationDecision $decision): BlockingPolicyEnforcementPoint
    {
        return new BlockingPolicyEnforcementPoint(
            new FakePolicyDecisionPoint($decision),
            new EnforcementPlanner([new ContentFilteringProvider(), new FailingSignalProvider()]),
        );
    }

    private function streamEnforcer(): StreamEnforcer
    {
        return new StreamEnforcer(
            new FakePolicyDecisionPoint(AuthorizationDecision::permit()),
            new StreamingPolicyEnforcementPoint(new EnforcementPlanner([]), EnforcementSignals::STREAM),
            $this->builder,
        );
    }

    /**
     * @param array<string, mixed> $obligation
     */
    private function permitWith(array $obligation): AuthorizationDecision
    {
        return new AuthorizationDecision(Decision::PERMIT, [$obligation]);
    }

    private function committedRowCount(): int
    {
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM patient');

        return is_numeric($count) ? (int) $count : -1;
    }
}
