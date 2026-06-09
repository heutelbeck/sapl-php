<?php

declare(strict_types=1);

namespace Sapl\Tests\Integration\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
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

/**
 * Proves the SQL shim against a real in-memory SQLite database through a real
 * Doctrine EntityManager. The SaplSqlFilter is registered and enabled, and the
 * active plan carries a sql:queryRewriting obligation. Doctrine pulls the
 * filter's predicate into the emitted SQL, so the obligation narrows the rows
 * the query returns.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class SaplSqlFilterDatabaseTest extends TestCase
{
    /** @var list<SignalKind> */
    private const array PRE_WITH_SQL = [
        SignalKind::DECISION,
        SignalKind::INPUT,
        SignalKind::OUTPUT,
        SignalKind::ERROR,
        SignalKind::SQL_QUERY,
    ];

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([__DIR__]);
        $config->enableNativeLazyObjects(true);
        $config->addFilter(SaplSqlFilter::FILTER_NAME, SaplSqlFilter::class);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->em = new EntityManager($connection, $config);

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->createSchema([$this->em->getClassMetadata(Patient::class)]);
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

    public function testEmittedSqlAndsTheParenWrappedPredicate(): void
    {
        ActivePlan::set($this->planFor(['type' => 'sql:queryRewriting', 'criteria' => [['column' => 'tenant_id', 'op' => '=', 'value' => 7]]]));

        $sql = $this->em->getRepository(Patient::class)->createQueryBuilder('p')->getQuery()->getSQL();

        self::assertIsString($sql);
        // Doctrine assigns the table alias dynamically and AND-merges the
        // paren-wrapped filter predicate scoped to that alias.
        self::assertMatchesRegularExpression('/\(\(\w+\.tenant_id = 7\)\)/', $sql);
    }

    public function testObligationNarrowsTheReturnedRows(): void
    {
        ActivePlan::set($this->planFor(['type' => 'sql:queryRewriting', 'criteria' => [['column' => 'tenant_id', 'op' => '=', 'value' => 7]]]));

        $names = $this->patientNames();

        self::assertSame(['Alice', 'Bob'], $names);
    }

    public function testWithoutAnActivePlanAllRowsAreReturned(): void
    {
        $names = $this->patientNames();

        self::assertSame(['Alice', 'Bob', 'Carol'], $names);
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
}
