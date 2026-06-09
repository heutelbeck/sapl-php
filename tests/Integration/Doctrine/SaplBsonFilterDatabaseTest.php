<?php

declare(strict_types=1);

namespace Sapl\Tests\Integration\Doctrine;

use Doctrine\ODM\MongoDB\Configuration;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\Driver\AttributeDriver;
use MongoDB\Client;
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

/**
 * Proves the Mongo shim against a real MongoDB through a real Doctrine ODM
 * DocumentManager. The SaplBsonFilter is registered and enabled, and the active
 * plan carries a mongo:queryRewriting obligation. Doctrine AND-merges the
 * filter's criteria into the query, so the obligation narrows the documents the
 * query returns.
 *
 * There is no in-memory MongoDB, so this is gated on the ext-mongodb extension
 * and a MONGODB_URI environment variable pointing at a live server; it is skipped
 * otherwise.
 */
#[RequiresPhpExtension('mongodb')]
final class SaplBsonFilterDatabaseTest extends TestCase
{
    /** @var list<SignalKind> */
    private const array PRE_WITH_MONGO = [
        SignalKind::DECISION,
        SignalKind::INPUT,
        SignalKind::OUTPUT,
        SignalKind::ERROR,
        SignalKind::MONGO_QUERY,
    ];

    private DocumentManager $dm;

    protected function setUp(): void
    {
        $uri = getenv('MONGODB_URI');
        if (false === $uri || '' === $uri) {
            self::markTestSkipped('Set MONGODB_URI to run the live MongoDB integration test.');
        }

        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([__DIR__]));
        $config->setDefaultDB('sapl_demo_test');
        $config->setHydratorDir(sys_get_temp_dir().'/sapl-odm-hydrators');
        $config->setHydratorNamespace('SaplOdmHydrators');
        $config->setProxyDir(sys_get_temp_dir().'/sapl-odm-proxies');
        $config->setProxyNamespace('SaplOdmProxies');
        $config->addFilter(SaplBsonFilter::FILTER_NAME, SaplBsonFilter::class);

        $client = new Client($uri);
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

    public function testObligationNarrowsTheReturnedDocuments(): void
    {
        ActivePlan::set($this->planFor(['type' => 'mongo:queryRewriting', 'criteria' => [['column' => 'tenantId', 'op' => '=', 'value' => 7]]]));

        $names = $this->patientNames();

        self::assertSame(['Alice', 'Bob'], $names);
    }

    public function testWithoutAnActivePlanAllDocumentsAreReturned(): void
    {
        $names = $this->patientNames();

        self::assertSame(['Alice', 'Bob', 'Carol'], $names);
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
}
