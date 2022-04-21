<?php

namespace Rompetomp\InertiaBundle\Tests;

use Rompetomp\InertiaBundle\RompetompInertiaBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\WebpackEncoreBundle\WebpackEncoreBundle;

class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new TwigBundle(),
            new RompetompInertiaBundle(),
            new WebpackEncoreBundle(),
        ];
    }

    protected function getKernelParameters(): array
    {
        return parent::getKernelParameters() + ['kernel.secret' => '$3cret'];
    }


    public function registerContainerConfiguration(LoaderInterface $loader)
    {
        $loader->load(__DIR__.'/config.yaml');
    }
}