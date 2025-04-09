<?php

namespace Rompetomp\InertiaBundle\Ssr;

use Rompetomp\InertiaBundle\Service\InertiaInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class HttpGateway implements GatewayInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private InertiaInterface $inertia)
    {
    }

    /**
     * Dispatch the Inertia page to the Server Side Rendering engine.
     */
    public function dispatch(array $page): ?Response
    {
        try {
            $response = $this->httpClient->request(
                'POST',
                $this->inertia->getSsrUrl(),
                [
                    'headers' => [
                        'Content-Type: application/json',
                        'Accept: application/json',
                    ],
                    'body' => json_encode($page),
                ]
            );
        } catch (\Exception) {
            return null;
        }

        if (is_null($response)) {
            return null;
        }

        $content = $response->toArray();

        return new Response(
            implode("\n", $content['head']),
            $content['body']
        );
    }
}
