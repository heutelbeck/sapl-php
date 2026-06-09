<?php

declare(strict_types=1);

namespace Sapl\Tests\Integration\Doctrine;

use Doctrine\ODM\MongoDB\Configuration;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\Driver\AttributeDriver;
use MongoDB\Client;
use MongoDB\Driver\Exception\ConnectionException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Sapl\Api\AuthorizationDecision;
use Sapl\Api\Decision;
use Sapl\Doctrine\Odm\SaplBsonFilter;
use Sapl\Pep\AccessDeniedException;
use Sapl\Pep\Constraints\ActivePlan;
use Sapl\Pep\Constraints\EnforcementPlan;
use Sapl\Pep\Constraints\EnforcementPlanner;
use Sapl\Pep\Constraints\Providers\MongoQueryRewritingProvider;
use Sapl\Pep\Constraints\SignalKind;
use Symfony\Component\Process\Process;
use Testcontainers\Container\Container;
use Testcontainers\Wait\WaitForExec;

/**
 * Proves the Mongo shim against a real MongoDB server running in a throwaway
 * docker container through a real Doctrine ODM DocumentManager. The
 * SaplBsonFilter is registered and enabled, and the active plan carries a
 * mongo:queryRewriting obligation. Doctrine AND-merges the filter's criteria
 * into the query, so the obligation narrows the documents the query returns from
 * the live database.
 *
 * The container is started once for the class and reused across tests. The class
 * skips cleanly when docker is unavailable, so the unit suite stays green without
 * a container runtime.
 */
#[RequiresPhpExtension('mongodb')]
final class SaplBsonFilterMongoTest extends TestCase
{
    private const string DB_NAME = 'sapl_demo_test';

    /** @var list<SignalKind> */
    private const array PRE_WITH_MONGO = [
        SignalKind::DECISION,
        SignalKind::INPUT,
        SignalKind::OUTPUT,
        SignalKind::ERROR,
        SignalKind::MONGO_QUERY,
    ];

    private static ?Container $container = null;
    private static string $uri = '';

    private DocumentManager $dm;

    public static function setUpBeforeClass(): void
    {
        if (!self::dockerAvailable()) {
            return;
        }

        self::$container = Container::make('mongo:7')
            ->withNetwork('bridge')
            ->withWait(new WaitForExec(['mongosh', '--quiet', '--eval', 'db.runCommand({ ping: 1 })']))
            ->run();

        self::$uri = sprintf('mongodb://%s:27017', self::$container->getAddress());
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
            self::markTestSkipped('Docker is unavailable. The live MongoDB integration test needs a container runtime.');
        }

        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__]));
        $config->setDefaultDB(self::DB_NAME);
        $config->setHydratorDir(sys_get_temp_dir().'/sapl-odm-hydrators');
        $config->setHydratorNamespace('SaplOdmHydrators');
        $config->setProxyDir(sys_get_temp_dir().'/sapl-odm-proxies');
        $config->setProxyNamespace('SaplOdmProxies');
        $config->addFilter(SaplBsonFilter::FILTER_NAME, SaplBsonFilter::class);

        $client = new Client(self::$uri);
        $this->dm = DocumentManager::create($client, $config);

        $collection = $this->dm->getDocumentCollection(PatientDocument::class);
        $collection->deleteMany([]);
        $this->dm->persist(new PatientDocument('P-001', 7, 'Alice'));
        $this->dm->persist(new PatientDocument('P-002', 7, 'Bob'));
        $this->dm->persist(new PatientDocument('P-003', 9, 'Carol'));
        $this->dm->flush();
        $this->dm->clear();

        $this->dm->getFilterCollection()->enable(SaplBsonFilter::FILTER_NAME);
    }

    protected function tearDown(): void
    {
        ActivePlan::reset();
    }

    public function testWithoutAnActivePlanAllDocumentsAreReturned(): void
    {
        $names = $this->patientNames();

        self::assertSame(['Alice', 'Bob', 'Carol'], $names);
    }

    public function testObligationNarrowsTheReturnedDocuments(): void
    {
        ActivePlan::set($this->planFor(['type' => 'mongo:queryRewriting', 'criteria' => [['column' => 'tenantId', 'op' => '=', 'value' => 7]]]));

        $names = $this->patientNames();

        self::assertSame(['Alice', 'Bob'], $names);
    }

    public function testMalformedObligationFailsClosedAtQueryTime(): void
    {
        ActivePlan::set($this->planFor(['type' => 'mongo:queryRewriting', 'conditions' => ["{'tenantId': 7}"]]));

        $this->expectException(AccessDeniedException::class);

        $this->patientNames();
    }

    /**
     * @return list<string>
     */
    private function patientNames(): array
    {
        $documents = $this->dm->getRepository(PatientDocument::class)->findBy([], ['id' => 'ASC']);

        return array_map(static fn (PatientDocument $document): string => $document->name, $documents);
    }

    /**
     * @param array<string, mixed> $obligation
     */
    private function planFor(array $obligation): EnforcementPlan
    {
        $decision = new AuthorizationDecision(Decision::PERMIT, [$obligation]);
        $planner = new EnforcementPlanner([new MongoQueryRewritingProvider()]);

        return $planner->plan($decision, self::PRE_WITH_MONGO);
    }

    private static function dockerAvailable(): bool
    {
        $process = new Process(['docker', 'info']);
        $process->run();

        return $process->isSuccessful();
    }

    private static function awaitConnectable(): void
    {
        for ($attempt = 0; $attempt < 30; ++$attempt) {
            try {
                (new Client(self::$uri))->selectDatabase(self::DB_NAME)->command(['ping' => 1]);

                return;
            } catch (ConnectionException) {
                usleep(300_000);
            }
        }
    }
}
