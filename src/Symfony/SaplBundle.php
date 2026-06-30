<?php

declare(strict_types=1);

namespace Sapl\Symfony;

use Doctrine\ODM\MongoDB\Query\Filter\BsonFilter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Filter\SQLFilter;
use Reflector;
use Sapl\Doctrine\Orm\DoctrineTransactionProvider;
use Sapl\Pdp\Http\HttpPdpClient;
use Sapl\Pdp\Http\HttpPdpClientOptions;
use Sapl\Pdp\PolicyDecisionPoint;
use Sapl\Pdp\Reconnect\BackoffPolicy;
use Sapl\Pep\BlockingPolicyEnforcementPoint;
use Sapl\Pep\Constraints\ConstraintHandlerProvider;
use Sapl\Pep\Constraints\EnforcementPlanner;
use Sapl\Pep\Constraints\Providers\ContentFilteringProvider;
use Sapl\Pep\Constraints\Providers\MongoQueryRewritingProvider;
use Sapl\Pep\Constraints\Providers\SqlQueryRewritingProvider;
use Sapl\Pep\Constraints\ShimSignalRegistry;
use Sapl\Pep\Constraints\SignalKind;
use Sapl\Pep\NoTransactionProvider;
use Sapl\Pep\Streaming\StreamingPolicyEnforcementPoint;
use Sapl\Pep\TransactionProvider;
use Sapl\Symfony\Proxy\SaplInterceptor;
use Sapl\Symfony\Proxy\SaplServiceProxyPass;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\DefaultsConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Registers the SAPL PEP with a Symfony application: the PDP client (from the
 * `sapl.pdp` configuration), the enforcement planner with its constraint handler
 * providers, and the controller subscriber that enforces `#[PreEnforce]` /
 * `#[PostEnforce]`.
 */
final class SaplBundle extends AbstractBundle
{
    private const string PROVIDER_TAG = 'sapl.constraint_handler_provider';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // A Reflector third parameter registers the autoconfigurator for every
        // target (class, method, ...), so the attributes are picked up on methods.
        $tagger = static function (ChildDefinition $definition, object $attribute, Reflector $reflector): void {
            $definition->addTag(SaplServiceProxyPass::TAG);
        };
        $container->registerAttributeForAutoconfiguration(PreEnforce::class, $tagger);
        $container->registerAttributeForAutoconfiguration(PostEnforce::class, $tagger);
        $container->registerAttributeForAutoconfiguration(StreamEnforce::class, $tagger);

        $container->addCompilerPass(new SaplServiceProxyPass());
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('transactional')->defaultFalse()->end()
                ->arrayNode('pdp')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('base_url')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('token')->defaultNull()->end()
                        ->scalarNode('username')->defaultNull()->end()
                        ->scalarNode('secret')->defaultNull()->end()
                        ->floatNode('timeout')->defaultValue(HttpPdpClientOptions::DEFAULT_TIMEOUT_SECONDS)->end()
                        ->booleanNode('verify_peer')->defaultTrue()->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->registerForAutoconfiguration(ConstraintHandlerProvider::class)
            ->addTag(self::PROVIDER_TAG);

        $pdp = is_array($config['pdp'] ?? null) ? $config['pdp'] : [];
        $services = $container->services()->defaults()->autowire()->autoconfigure();

        $services->set('sapl.pdp_client_options', HttpPdpClientOptions::class)
            ->autowire(false)
            ->args([
                '$baseUrl' => $pdp['base_url'] ?? '',
                '$token' => $pdp['token'] ?? null,
                '$username' => $pdp['username'] ?? null,
                '$secret' => $pdp['secret'] ?? null,
                '$timeoutSeconds' => $pdp['timeout'] ?? HttpPdpClientOptions::DEFAULT_TIMEOUT_SECONDS,
                '$streamInactivityTimeoutSeconds' => HttpPdpClientOptions::DEFAULT_STREAM_INACTIVITY_TIMEOUT_SECONDS,
                '$retryBaseDelaySeconds' => BackoffPolicy::DEFAULT_BASE_SECONDS,
                '$retryMaxDelaySeconds' => BackoffPolicy::DEFAULT_CAP_SECONDS,
                '$verifyPeer' => $pdp['verify_peer'] ?? true,
            ]);

        $services->set(HttpPdpClient::class)
            ->autowire(false)
            ->args([service('sapl.pdp_client_options')]);
        $services->alias(PolicyDecisionPoint::class, HttpPdpClient::class);

        $services->set(EnforcementPlanner::class)
            ->args([tagged_iterator(self::PROVIDER_TAG)]);

        $services->set(ContentFilteringProvider::class);

        $this->registerSqlShim($services);
        $this->registerMongoShim($services);
        $this->registerTransactionProvider($services, (bool) ($config['transactional'] ?? false));

        $services->set(TokenStorageSubjectResolver::class);
        $services->alias(SubjectResolver::class, TokenStorageSubjectResolver::class);
        $services->set(ExpressionLanguage::class);
        $services->set(AuthorizationSubscriptionBuilder::class);

        $services->set(BlockingPolicyEnforcementPoint::class);
        $services->set(StreamingPolicyEnforcementPoint::class)
            ->arg('$supportedSignals', EnforcementSignals::STREAM);
        $services->set(StreamEnforcer::class);
        $services->set(EnforcementControllerSubscriber::class);
        $services->set(AccessDeniedExceptionListener::class);
        $services->set(SaplInterceptor::class);
    }

    /**
     * Register the SQL query-rewriting shim when Doctrine ORM is installed. The
     * provider is tagged as a constraint handler, and the SQL_QUERY signal is
     * advertised so the planner admits a sql:queryRewriting obligation rather than
     * failing it closed. The SaplSqlFilter is registered and enabled in the
     * application's Doctrine configuration, since the bundle cannot edit it.
     */
    private function registerSqlShim(DefaultsConfigurator $services): void
    {
        if (!class_exists(SQLFilter::class)) {
            return;
        }
        $services->set(SqlQueryRewritingProvider::class);
        ShimSignalRegistry::register(SignalKind::SQL_QUERY);
    }

    /**
     * Register the Mongo query-rewriting shim when Doctrine ODM is installed. The
     * provider is tagged as a constraint handler, and the MONGO_QUERY signal is
     * advertised so the planner admits a mongo:queryRewriting obligation rather
     * than failing it closed. The SaplBsonFilter is registered and enabled in the
     * application's Doctrine ODM configuration, since the bundle cannot edit it.
     */
    private function registerMongoShim(DefaultsConfigurator $services): void
    {
        if (!class_exists(BsonFilter::class)) {
            return;
        }
        $services->set(MongoQueryRewritingProvider::class);
        ShimSignalRegistry::register(SignalKind::MONGO_QUERY);
    }

    /**
     * Wire the transaction provider that the interception layer wraps every
     * enforced invocation in. The default is the inert {@see NoTransactionProvider},
     * so behavior is unchanged unless an application opts in. When `sapl.transactional`
     * is set and Doctrine ORM is installed, the Doctrine-backed provider takes over,
     * giving every PreEnforce and PostEnforce invocation a transaction boundary that
     * rolls back when enforcement denies after the protected method has written.
     */
    private function registerTransactionProvider(DefaultsConfigurator $services, bool $transactional): void
    {
        $services->set(NoTransactionProvider::class);
        $services->alias(TransactionProvider::class, NoTransactionProvider::class);

        if (!$transactional || !interface_exists(EntityManagerInterface::class)) {
            return;
        }
        $services->set(DoctrineTransactionProvider::class);
        $services->alias(TransactionProvider::class, DoctrineTransactionProvider::class);
    }
}
