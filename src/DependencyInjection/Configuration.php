<?php

namespace Rompetomp\InertiaBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Class Configuration.
 *
 * @author  Hannes Vermeire <hannes@codedor.be>
 *
 * @since   2019-08-02
 */
class Configuration implements ConfigurationInterface
{
    /**
     * Generates the configuration tree builder.
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('rompetomp_inertia');
        $treeBuilder->getRootNode()
            ->children()
            ->scalarNode('root_view')->defaultValue('app.html.twig')->end()
            ->arrayNode('ssr')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')->defaultFalse()->end()
            ->scalarNode('url')->defaultValue('')->end()
            ->end()
            ->end()
            ->end();

        return $treeBuilder;
    }
}
