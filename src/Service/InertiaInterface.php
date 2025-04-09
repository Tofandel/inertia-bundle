<?php

namespace Rompetomp\InertiaBundle\Service;

use Illuminate\Contracts\Support\Arrayable;
use Rompetomp\InertiaBundle\LazyProp;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interface InertiaInterface.
 *
 * @author  Hannes Vermeire <hannes@codedor.be>
 *
 * @since   2019-08-09
 */
interface InertiaInterface
{
    /**
     * Adds global component properties for the templating system.
     */
    public function share(string|array|Arrayable $key, mixed $value = null): self;

    public function getShared(?string $key = null, mixed $default = null): mixed;

    /**
     * Adds global view data for the templating system.
     */
    public function viewData(array|string $key, mixed $value = null): self;

    public function getViewData(?string $key = null, mixed $default = null): mixed;

    public function version(string|callable|null $version): self;

    /**
     * Adds a context for the serializer.
     */
    public function context(array|string $key, mixed $value = null): self;

    public function getContext(?string $key = null): mixed;

    public function getVersion(): string;

    public function setRootView(string $rootView): void;

    public function getRootView(): string;

    /**
     * Check if it using ssr.
     */
    public function isSsr(): bool;

    /**
     * Set the ssr url where it will fetch its content.
     */
    public function setSsrUrl(string $url): void;

    /**
     * Get the ssr url where it will fetch its content.
     */
    public function getSsrUrl(): string;

    public function lazy(callable|string|array $callback): LazyProp;

    /**
     * @param string $component component name
     * @param array  $props     component properties
     * @param array  $viewData  templating view data
     */
    public function render(string $component, array $props = [], array $viewData = []): Response;

    public function isInertiaRequest(): bool;

    public function location($url): Response;
}
