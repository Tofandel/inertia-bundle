<?php

namespace Rompetomp\InertiaBundle\Service;

use GuzzleHttp\Promise\PromiseInterface\PromiseInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceResponse;
use Illuminate\Support\Arr;
use Rompetomp\InertiaBundle\LazyProp;
use Rompetomp\InertiaBundle\Utils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Twig\Environment;
use Illuminate\Support\Traits\Macroable;

class Inertia implements InertiaInterface
{
    use Macroable;

    protected array $props = [];
    protected array $viewData = [];
    protected array $context = [];

    protected $version = null;

    /**
     * Inertia constructor.
     */
    public function __construct(
        protected string               $rootView,
        protected Environment          $engine,
        protected RequestStack         $requestStack,
        protected ?SerializerInterface $serializer = null)
    {
    }

    /**
     * @param string|array|Arrayable $key
     * @param mixed|null $value
     */
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

    public function getShared(string $key = null, mixed $default = null): mixed
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

    public function context(array|string $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->context = array_merge($this->props, $key);
        } else {
            $this->context[$key] = $value;
        }

        return $this;
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

        return (string)$version;
    }

    public function lazy(callable $callback): LazyProp
    {
        return new LazyProp($callback);
    }

    public function getViewData(string $key = null, mixed $default = null): mixed
    {
        if ($key) {
            return Arr::get($this->viewData, $key, $default);
        }

        return $this->viewData;
    }

    public function getContext(string $key = null, mixed $default = null): mixed
    {
        if ($key) {
            return Arr::get($this->context, $key, $default);
        }

        return $this->context;
    }

    public function setRootView(string $rootView): void
    {
        $this->rootView = $rootView;
    }

    public function getRootView(): string
    {
        return $this->rootView;
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
                return ! ($prop instanceof LazyProp);
            });

        $props = $this->resolvePropertyInstances($props, $request);

        $page = [
            'component' => $component,
            'props' => $props,
            'url' => $request->getBaseUrl().$request->getRequestUri(),
            'version' => $this->getVersion(),
        ];

        if (Utils::isInertiaRequest($request)) {
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


    /**
     * Serializes the given objects with the given context if the Symfony Serializer is available. If not, uses `json_encode`.
     *
     * @see https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/AJAX_Security_Cheat_Sheet.md#always-return-json-with-an-object-on-the-outside
     *
     * @param array $context
     *
     * @return array @return array returns a decoded array of the previously JSON-encoded data, so it can safely be given to {@see JsonResponse}
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
            ], $this->context));
        } else {
            $json = json_encode($page);
        }

        return $json;
    }

    /**
     * Resolve all necessary class instances in the given props.
     *
     * @param  array  $props
     * @param  Request  $request
     * @param  bool  $unpackDotProps
     * @return array
     */
    public function resolvePropertyInstances(array $props, Request $request, bool $unpackDotProps = true): array
    {
        foreach ($props as $key => $value) {
            if (is_callable($value) || $value instanceof LazyProp) {
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
