<?php

namespace Rompetomp\InertiaBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * Class InertiaExtension.
 *
 * @author  Hannes Vermeire <hannes@codedor.be>
 *
 * @since   2019-08-09
 */
class InertiaExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [new TwigFunction('inertia', [$this, 'inertiaFunction'], ['needs_context' => true])];
    }

    public function inertiaFunction(array $context): Markup
    {
        return new Markup('<div id="app" data-page="' . htmlspecialchars($context['_serialized_page']) . '"></div>', 'UTF-8');
    }
}
