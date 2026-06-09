<?php

declare(strict_types=1);

namespace Sapl\Tests\Integration\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Sapl\Api\AuthorizationDecision;
use Sapl\Api\Decision;
use Sapl\Doctrine\Orm\SaplSqlFilter;
use Sapl\Pep\AccessDeniedException;
use Sapl\Pep\Constraints\ActivePlan;
use Sapl\Pep\Constraints\EnforcementPlan;
use Sapl\Pep\Constraints\EnforcementPlanner;
use Sapl\Pep\Constraints\Providers\SqlQueryRewritingProvider;
use Sapl\Pep\Constraints\SignalKind;
use Symfony\Component\Process\Process;
use Testcontainers\Container\Container;
use Testcontainers\Wait\WaitForExec;

/**
 * Proves the SQL shim against a real PostgreSQL server running in a throwaway
 * docker container through a real Doctrine EntityManager over pdo_pgsql. The
 * SaplSqlFilter is registered and enabled, and the active plan carries a
 * sql:queryRewriting obligation. Doctrine pulls the filter's predicate into the
 * emitted SQL, so the obligation narrows the rows the query returns from the
 * live database.
 *
 * The container is started once for the class and reused across tests. The class
 * skips cleanly when docker is unavailable, so the unit suite stays green without
 * a container runtime.
 */
#[RequiresPhpExtension('pdo_pgsql')]
final class SaplSqlFilterPostgresTest extends TestCase
{
    private const string DB_NAME = 'sapl';
    private const string DB_PASSWORD = 'test';
    private const string DB_USER = 'postgres';

    /** @var list<SignalKind> */
    private const array PRE_WITH_SQL = [
        SignalKind::DECISION,
        SignalKind::INPUT,
        SignalKind::OUTPUT,
        SignalKind::ERROR,
        SignalKind::SQL_QUERY,
    ];

    private static ?Container $container = null;
    private static string $host = '';

    private EntityManagerInterface $em;

    public static function setUpBeforeClass(): void
    {
        if (!self::dockerAvailable()) {
            return;
        }

        self::$container = Container::make('postgres:16')
            ->withEnvironment('POSTGRES_PASSWORD', self::DB_PASSWORD)
            ->withEnvironment('POSTGRES_DB', self::DB_NAME)
            ->withNetwork('bridge')
            ->withWait(new WaitForExec(['pg_isready', '-h', '127.0.0.1', '-U', self::DB_USER]))
            ->run();

        self::$host = self::$container->getAddress();
        self::awaitConnectable();
    }

    public static function tearDownAfterClass(): void
    {
        self::$container?->stop();
        self::$container = null;
    }

    protected function setUp(): void
    {
        if (null === self::$container) {
            self::markTestSkipped('Docker is unavailable. The live PostgreSQL integration test needs a container runtime.');
        }

        $config = ORMSetup::createAttributeMetadataConfig([__DIR__]);
        $config->enableNativeLazyObjects(true);
        $config->addFilter(SaplSqlFilter::FILTER_NAME, SaplSqlFilter::class);
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => self::$host,
            'port' => 5432,
            'dbname' => self::DB_NAME,
            'user' => self::DB_USER,
            'password' => self::DB_PASSWORD,
        ], $config);
        $this->em = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->em);
        $metadata = [$this->em->getClassMetadata(Patient::class)];
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
        $this->em->getFilters()->enable(SaplSqlFilter::FILTER_NAME);

        $this->em->persist(new Patient(1, 7, 'Alice'));
        $this->em->persist(new Patient(2, 7, 'Bob'));
        $this->em->persist(new Patient(3, 9, 'Carol'));
        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        ActivePlan::reset();
    }

    public function testWithoutAnActivePlanAllRowsAreReturned(): void
    {
        $names = $this->patientNames();

        self::assertSame(['Alice', 'Bob', 'Carol'], $names);
    }

    public function testObligationNarrowsTheReturnedRows(): void
    {
        ActivePlan::set($this->planFor(['type' => 'sql:queryRewriting', 'criteria' => [['column' => 'tenant_id', 'op' => '=', 'value' => 7]]]));

        $names = $this->patientNames();

        self::assertSame(['Alice', 'Bob'], $names);
    }

    public function testMalformedObligationFailsClosedAtQueryTime(): void
    {
        ActivePlan::set($this->planFor(['type' => 'sql:queryRewriting', 'criteria' => [['column' => 'tenant_id', 'op' => 'between', 'value' => 7]]]));

        $this->expectException(AccessDeniedException::class);

        $this->patientNames();
    }

    /**
     * @return list<string>
     */
    private function patientNames(): array
    {
        $patients = $this->em->getRepository(Patient::class)->findBy([], ['id' => 'ASC']);

        return array_map(static fn (Patient $patient): string => $patient->name, $patients);
    }

    /**
     * @param array<string, mixed> $obligation
     */
    private function planFor(array $obligation): EnforcementPlan
    {
        $decision = new AuthorizationDecision(Decision::PERMIT, [$obligation]);
        $planner = new EnforcementPlanner([new SqlQueryRewritingProvider()]);

        return $planner->plan($decision, self::PRE_WITH_SQL);
    }

    private static function dockerAvailable(): bool
    {
        $process = new Process(['docker', 'info']);
        $process->run();

        return $process->isSuccessful();
    }

    private static function awaitConnectable(): void
    {
        $dsn = sprintf('pgsql:host=%s;port=5432;dbname=%s', self::$host, self::DB_NAME);
        for ($attempt = 0; $attempt < 30; ++$attempt) {
            try {
                new PDO($dsn, self::DB_USER, self::DB_PASSWORD);

                return;
            } catch (PDOException) {
                usleep(300_000);
            }
        }
    }
}
