<?php

declare(strict_types=1);

namespace Sapl\Symfony;

use Reflector;
use Sapl\Pdp\Http\HttpPdpClient;
use Sapl\Pdp\Http\HttpPdpClientOptions;
use Sapl\Pdp\PolicyDecisionPoint;
use Sapl\Pdp\Reconnect\BackoffPolicy;
use Sapl\Pep\BlockingPolicyEnforcementPoint;
use Sapl\Pep\Constraints\ConstraintHandlerProvider;
use Sapl\Pep\Constraints\EnforcementPlanner;
use Sapl\Pep\Constraints\Providers\ContentFilteringProvider;
use Sapl\Pep\Streaming\StreamingPolicyEnforcementPoint;
use Sapl\Symfony\Proxy\SaplInterceptor;
use Sapl\Symfony\Proxy\SaplServiceProxyPass;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

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
                $pdp['base_url'] ?? '',
                $pdp['token'] ?? null,
                $pdp['username'] ?? null,
                $pdp['secret'] ?? null,
                null,
                $pdp['timeout'] ?? HttpPdpClientOptions::DEFAULT_TIMEOUT_SECONDS,
                BackoffPolicy::DEFAULT_BASE_SECONDS,
                BackoffPolicy::DEFAULT_CAP_SECONDS,
                $pdp['verify_peer'] ?? true,
            ]);

        $services->set(HttpPdpClient::class)
            ->autowire(false)
            ->args([service('sapl.pdp_client_options')]);
        $services->alias(PolicyDecisionPoint::class, HttpPdpClient::class);

        $services->set(EnforcementPlanner::class)
            ->args([tagged_iterator(self::PROVIDER_TAG)]);

        $services->set(ContentFilteringProvider::class);

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
}
