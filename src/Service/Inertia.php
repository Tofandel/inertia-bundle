<?php

namespace Rompetomp\InertiaBundle\Service;

use GuzzleHttp\Promise\PromiseInterface\PromiseInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Traits\Macroable;
use Rompetomp\InertiaBundle\LazyProp;
use Rompetomp\InertiaBundle\Utils;
use Symfony\Component\Asset\Package;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\PathPackage;
use Symfony\Component\Asset\VersionStrategy\JsonManifestVersionStrategy;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Twig\Environment;

class Inertia implements InertiaInterface
{
    use Macroable;

    protected array $props = [];

    protected array $viewData = [];

    protected array $context = [];

    protected bool $useSsr = false;

    protected string $ssrUrl = '';

    protected string|\Closure|null $version = null;

    /**
     * Inertia constructor.
     */
    public function __construct(
        protected string $rootView,
        protected Environment $engine,
        protected RequestStack $requestStack,
        protected ?SerializerInterface $serializer = null,
        protected ?Packages $package = null)
    {
        if (isset($this->package)) {
            $this->version = function () {
                /** @var PathPackage $package */
                $package = $this->package->getPackage();
                if ($package instanceof Package) {
                    $rp = new \ReflectionProperty(Package::class, 'versionStrategy');
                    $rp->setAccessible(true);
                    $strategy = $rp->getValue($package);
                    if ($strategy instanceof JsonManifestVersionStrategy) {
                        $strategy->getVersion('');
                        $rp = new \ReflectionProperty($strategy, 'manifestData');
                        $rp->setAccessible(true);
                        $version = md5(json_encode($rp->getValue($strategy)));
                    }
                }
                if (empty($version)) {
                    $version = explode('?', $this->package->getVersion('build/app.js') ?: $this->package->getVersion('build/main.js'));

                    if (1 === count($version)) {
                        $version = md5($version[0]);
                    } else {
                        $version = array_pop($version);
                    }
                }

                return $version;
            };
        }
    }

    public function share(string|array|Arrayable $key, $value = null): self
    {
        if (is_array($key)) {
            $this->props = array_merge($this->props, $key);
        } elseif ($key instanceof Arrayable) {
            $this->props = array_merge($this->props, $key->toArray());
        } else {
            Arr::set($this->props, $key, $value);
        }

        return $this;
    }

    public function getShared(?string $key = null, mixed $default = null): mixed
    {
        if ($key) {
            return Arr::get($this->props, $key, $default);
        }

        return $this->props;
    }

    public function flushShared(): void
    {
        $this->props = [];
    }

    public function withViewData(array|string $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->viewData = array_merge($this->viewData, $key);
        } else {
            $this->viewData[$key] = $value;
        }

        return $this;
    }

    public function viewData(array|string $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->viewData = array_merge($this->viewData, $key);
        } else {
            $this->viewData[$key] = $value;
        }

        return $this;
    }

    public function getViewData(?string $key = null, mixed $default = null): mixed
    {
        if ($key) {
            return Arr::get($this->viewData, $key, $default);
        }

        return $this->viewData;
    }

    public function context(array|string $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->context = array_merge($this->props, $key);
        } else {
            $this->context[$key] = $value;
        }

        return $this;
    }

    public function getContext(?string $key = null, mixed $default = null): mixed
    {
        if ($key) {
            return Arr::get($this->context, $key, $default);
        }

        return $this->context;
    }

    public function version(string|callable|null $version): self
    {
        $this->version = $version;

        return $this;
    }

    public function getVersion(): string
    {
        $version = is_callable($this->version)
            ? call_user_func($this->version)
            : $this->version;

        return (string) $version;
    }

    public function setRootView(string $rootView): void
    {
        $this->rootView = $rootView;
    }

    public function getRootView(): string
    {
        return $this->rootView;
    }

    public function useSsr(bool $useSsr): void
    {
        $this->useSsr = $useSsr;
    }

    public function isSsr(): bool
    {
        return $this->useSsr;
    }

    public function setSsrUrl(string $url): void
    {
        $this->ssrUrl = $url;
    }

    public function getSsrUrl(): string
    {
        return $this->ssrUrl;
    }

    public function render(string $component, array $props = [], array $viewData = []): Response
    {
        $viewData = array_merge($this->viewData, $viewData);
        $props = array_merge($this->props, $props);

        $request = $this->requestStack->getCurrentRequest();

        $only = array_filter(explode(',', $request->headers->get('X-Inertia-Partial-Data', '')));

        $props = ($only && $request->headers->get('X-Inertia-Partial-Component') === $component)
            ? Arr::only($props, $only)
            : array_filter($props, static function ($prop) {
                return !($prop instanceof LazyProp);
            });

        $props = $this->resolvePropertyInstances($props, $request);

        $page = [
            'component' => $component,
            'props' => $props,
            'url' => $request->getBaseUrl().$request->getRequestUri(),
            'version' => $this->getVersion(),
        ];

        if ($this->isInertiaRequest()) {
            return new JsonResponse($this->serialize($page), 200, [
                'Vary' => 'Accept',
                'X-Inertia' => 'true',
            ], true);
        }

        return new Response($this->engine->render($this->rootView, $viewData + ['page' => $page, '_serialized_page' => $this->serialize($page)]));
    }

    public function isInertiaRequest(): bool
    {
        return Utils::isInertiaRequest($this->requestStack->getCurrentRequest());
    }

    /**
     * @param string|RedirectResponse $url
     */
    public function location($url): Response
    {
        if ($url instanceof RedirectResponse) {
            $url = $url->getTargetUrl();
        }

        if ($this->isInertiaRequest()) {
            return new Response('', 409, ['X-Inertia-Location' => $url]);
        }

        return new RedirectResponse($url);
    }

    public function lazy(callable|string|array $callback): LazyProp
    {
        if (is_string($callback)) {
            $callback = explode('::', $callback, 2);
        }

        return new LazyProp($callback);
    }

    /**
     * Serializes the given objects with the given context if the Symfony Serializer is available. If not, uses `json_encode`.
     *
     * @see https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/AJAX_Security_Cheat_Sheet.md#always-return-json-with-an-object-on-the-outside
     *
     * @return string returns a json encoded string of the data, so it can safely be given to {@see JsonResponse}
     */
    private function serialize(array $page): string
    {
        if (null !== $this->serializer) {
            $json = $this->serializer->serialize($page, 'json', array_merge([
                'json_encode_options' => JsonResponse::DEFAULT_ENCODING_OPTIONS,
                AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER => function () {
                    return null;
                },
                AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => true,
                AbstractObjectNormalizer::ENABLE_MAX_DEPTH => true,
            ], $this->context));
        } else {
            $json = json_encode($page);
        }

        return $json;
    }

    /**
     * Resolve all necessary class instances in the given props.
     */
    public function resolvePropertyInstances(array $props, Request $request, bool $unpackDotProps = true): array
    {
        foreach ($props as $key => $value) {
            if ($value instanceof \Closure || $value instanceof LazyProp) {
                $value = call_user_func($value);
            }

            if ($value instanceof PromiseInterface) {
                $value = $value->wait();
            }

            if ($value instanceof ResourceResponse || $value instanceof JsonResource) {
                $value = $value->toResponse($request)->getData(true);
            }

            if ($value instanceof Arrayable) {
                $value = $value->toArray();
            }

            if (is_array($value)) {
                $value = $this->resolvePropertyInstances($value, $request, false);
            }

            if ($unpackDotProps && str_contains($key, '.')) {
                Arr::set($props, $key, $value);
                unset($props[$key]);
            } else {
                $props[$key] = $value;
            }
        }

        return $props;
    }
}
