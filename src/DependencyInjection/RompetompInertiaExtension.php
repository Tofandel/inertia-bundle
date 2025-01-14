<?php

namespace Rompetomp\InertiaBundle\DependencyInjection;

use Exception;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;

/**
 * Class RompetompInertiaExtension.
 *
 * @author  Hannes Vermeire <hannes@codedor.be>
 *
 * @since   2019-08-02
 */
class RompetompInertiaExtension extends ConfigurableExtension
{
    /**
     * Configures the passed container according to the merged configuration.
     *
     * @throws Exception
     */
    protected function loadInternal(array $mergedConfig, ContainerBuilder $container)
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $definition = $container->getDefinition('rompetomp_inertia.inertia');
        $definition->setArgument('$rootView', $mergedConfig['root_view']);
        $definition->addMethodCall('useSsr', [$mergedConfig['ssr']['enabled']]);
        $definition->addMethodCall('setSsrUrl', [$mergedConfig['ssr']['url']]);
    }
}
