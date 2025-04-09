<?php

namespace Rompetomp\InertiaBundle\EventListener;

use Illuminate\Contracts\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Rompetomp\InertiaBundle\Service\InertiaInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Class InertiaListener.
 */
class InertiaListener
{
    public function __construct(
        protected ParameterBagInterface $parameterBag,
        protected InertiaInterface $inertia,
        protected bool $debug)
    {
    }

    /**
     * Defines the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     */
    public function share(Request $request): array
    {
        return [
            'errors' => fn () => self::resolveValidationErrors($request, true),
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->inertia->isInertiaRequest()) {
            return;
        }

        $request = $event->getRequest();

        $this->inertia->share($this->share($request));

        if ('GET' === $request->getMethod() && $request->headers->get('X-Inertia-Version') !== $this->inertia->getVersion()) {
            $this->onVersionChange($event);
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$this->inertia->isInertiaRequest()) {
            return;
        }
        $response = $event->getResponse();
        $request = $event->getRequest();

        if ($response->isOk() && empty($response->getContent())) {
            $response = $this->onEmptyResponse($event);
        }

        if ($this->debug && $event->getRequest()->isXmlHttpRequest()) {
            $response->headers->set('Symfony-Debug-Toolbar-Replace', 1);
        }

        if (302 === $response->getStatusCode() && in_array($request->getMethod(), ['PUT', 'PATCH', 'DELETE'])) {
            $response->setStatusCode(303);
        }
    }

    /**
     * Determines what to do when an Inertia action returned with no response.
     * By default, we'll redirect the user back to where they came from.
     */
    public function onEmptyResponse(ResponseEvent $event): Response
    {
        $request = $event->getRequest();
        $event->setResponse($response = new RedirectResponse($request->headers->get('referer', $request->getSchemeAndHttpHost())));

        return $response;
    }

    /**
     * Determines what to do when the Inertia asset version has changed.
     * By default, we'll initiate a client-side location visit to force an update.
     */
    public function onVersionChange(RequestEvent $event): Response
    {
        $event->setResponse($response = $this->inertia->location($event->getRequest()->getUriForPath('')));

        return $response;
    }

    /**
     * Resolves and prepares validation errors in such
     * a way that they are easier to use client-side.
     */
    public static function resolveValidationErrors(Request $request, $flush = false): array
    {
        if (!$request->getSession()->has('errors')) {
            return [];
        }

        /** @var ViewErrorBag $errors */
        $errors = $request->getSession()->get('errors');
        if ($flush) {
            $request->getSession()->remove('errors');
        }

        $bags = array_map(fn (MessageBag $bag) => array_map(fn ($errors) => $errors[0], $bag->messages()), $errors->getBags());

        if (isset($bags['default']) && $request->headers->has('x-inertia-error-bag')) {
            return [$request->headers->get('x-inertia-error-bag') => $bags['default']];
        } elseif (isset($bags['default'])) {
            return $bags['default'];
        }

        return $bags;
    }
}
