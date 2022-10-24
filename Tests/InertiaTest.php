<?php

namespace Rompetomp\InertiaBundle\Tests;

use ArrayObject;
use DateTime;
use Mockery;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Rompetomp\InertiaBundle\EventListener\InertiaListener;
use Rompetomp\InertiaBundle\LazyProp;
use Rompetomp\InertiaBundle\Service\Inertia;
use Rompetomp\InertiaBundle\Service\InertiaInterface;
use stdClass;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Twig\Environment;

class InertiaTest extends TestCase
{
    /** @var Inertia */
    private $inertia;
    /** @var LegacyMockInterface|MockInterface|Environment */
    private $environment;
    /** @var LegacyMockInterface|MockInterface|RequestStack */
    private $requestStack;
    /** @var LegacyMockInterface|MockInterface|Serializer|null */
    private $serializer;

    public function setUp(): void
    {
        $this->serializer = null;
        $this->environment = Mockery::mock(Environment::class);
        $this->requestStack = Mockery::mock(RequestStack::class);

        $this->inertia = new Inertia('app.twig.html', $this->environment, $this->requestStack, $this->serializer);
    }

    public function testServiceWiring()
    {
        $kernel = new TestKernel('test', true);
        $kernel->boot();
        $container = $kernel->getContainer();

        $inertia = $container->get(InertiaInterface::class);
        $this->assertInstanceOf(Inertia::class, $inertia);
    }

    public function testSharedSingle()
    {
        $this->inertia->share('app_name', 'Testing App 1');
        $this->inertia->share('app_version', '1.0.0');
        $this->assertEquals('Testing App 1', $this->inertia->getShared('app_name'));
        $this->assertEquals('1.0.0', $this->inertia->getShared('app_version'));
    }

    public function testSharedMultiple()
    {
        $this->inertia->share('app_name', 'Testing App 2');
        $this->inertia->share('app_version', '2.0.0');
        $this->assertEquals(
            [
                'app_version' => '2.0.0',
                'app_name' => 'Testing App 2',
            ],
            $this->inertia->getShared()
        );
    }

    public function testVersion()
    {
        $this->assertEquals("", $this->inertia->getVersion());
        $this->inertia->version('1.2.3');
        $this->assertEquals($this->inertia->getVersion(), '1.2.3');
    }

    public function testRootView()
    {
        $this->assertEquals('app.twig.html', $this->inertia->getRootView());
    }

    public function testSetRootView()
    {
        $this->inertia->setRootView('other-root.twig.html');
        $this->assertEquals('other-root.twig.html', $this->inertia->getRootView());
    }

    protected function makeDispatcher(?Kernel $kernel = null)
    {
        $dispatcher = new EventDispatcher();
        $listener = new InertiaListener(new ParameterBag(['assets.json_manifest_path' => __DIR__ . '/manifest.json']), $kernel ? $kernel->getContainer()->get(InertiaInterface::class) : $this->inertia, true);
        $dispatcher->addListener('onKernelRequest', [$listener, 'onKernelRequest']);
        $dispatcher->addListener('onKernelResponse', [$listener, 'onKernelResponse']);

        if (isset($kernel)) {
            /** @var RequestStack $requestStack */
            $requestStack = $kernel->getContainer()->get('request_stack');
            $requestStack->push($this->requestStack->getCurrentRequest());
        }

        return $dispatcher;
    }

    protected function makeRequest(bool $inertia = true, bool $front = false, string $partial_component = null, ?array $partial_data = null)
    {
        $mockRequest = Mockery::mock(Request::class);

        $headers = [];
        switch (true) {
            case !!$partial_data:
                $headers += ['X-Inertia-Partial-Data' => join(',', $partial_data)];
            // no break
            case !!$partial_component:
                $partial_component = explode('|', $partial_component);
                $headers += ['X-Inertia-Partial-Component' => $partial_component[0]];
                $headers += ['X-Inertia-Version' => $partial_component[1] ?? ''];
            // no break
            case $inertia:
                $headers += ['X-Inertia' => true];
        }

        $mockRequest->headers = new HeaderBag($headers);
        $mockRequest->allows('getSchemeAndHttpHost')->andReturn('https://example.test');
        $mockRequest->allows('isXmlHttpRequest')->andReturn(!$front);
        $mockRequest->allows('getMethod')->andReturns($front ? 'GET' : 'POST');
        $mockRequest->allows('getBaseUrl')->andReturns('/foo');
        $mockRequest->allows('getRequestUri')->andReturns('https://example.test/foo');
        $mockRequest->allows('getUriForPath')->andReturnUsing(fn($path) => 'https://example.test/foo/' . $path);
        $this->requestStack->allows()->getCurrentRequest()->andReturns($mockRequest);

        return $mockRequest;
    }

    public function testEventListener(): void
    {
        $kernel = Mockery::mock(Kernel::class);
        $request = $this->makeRequest();
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $this->makeDispatcher()->dispatch($event, 'onKernelRequest');

        $this->assertNull($event->getResponse());

        $response = new Response();
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
        $this->makeDispatcher()->dispatch($event, 'onKernelResponse');

        $this->assertInstanceOf(Response::class, $event->getResponse());
    }

    public function testEventListenerDiffVersion(): void
    {

        $kernel = new TestKernel('test', true);
        $kernel->boot();

        $request = $this->makeRequest(true, true, 'Dashboard|some-other-version');

        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $this->makeDispatcher($kernel)->dispatch($event, 'onKernelRequest');

        $this->assertEquals(
            md5(json_encode(json_decode(file_get_contents(__DIR__ . '/manifest.json')))),
            $kernel->getContainer()->get(InertiaInterface::class)->getVersion()
        );
        $this->assertEquals(409, $event->getResponse()->getStatusCode());
        $this->assertEquals('https://example.test/foo/', $event->getResponse()->headers->get('X-Inertia-Location'));
    }

    public function testRenderProps()
    {
        $this->makeRequest();

        $this->inertia = new Inertia('app.twig.html', $this->environment, $this->requestStack, $this->serializer);

        $response = $this->inertia->render('Dashboard', ['test' => 123]);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertEquals(['test' => 123], $data['props']);
    }

    public function testRenderSharedProps()
    {
        $this->makeRequest();

        $this->inertia = new Inertia('app.twig.html', $this->environment, $this->requestStack, $this->serializer);
        $this->inertia->share('app_name', 'Testing App 3');
        $this->inertia->share('app_version', '2.0.0');

        $response = $this->inertia->render('Dashboard', ['test' => 123]);
        $data = json_decode($response->getContent(), true);
        $this->assertEquals(['test' => 123, 'app_name' => 'Testing App 3', 'app_version' => '2.0.0'], $data['props']);
    }

    public function testRenderClosureProps()
    {
        $this->makeRequest();

        $this->inertia = new Inertia('app.twig.html', $this->environment, $this->requestStack, $this->serializer);

        /** @var JsonResponse $response */
        $response = $this->inertia->render('Dashboard', ['test' => function () {
            return 'test-value';
        }]);
        $this->assertEquals(
            'test-value',
            json_decode($response->getContent(), true)['props']['test']
        );
    }

    public function testRenderDoc()
    {
        $this->makeRequest();

        $this->environment->allows('render')->andReturn('<div>123</div>');

        $this->inertia = new Inertia('app.twig.html', $this->environment, $this->requestStack, $this->serializer);

        $response = $this->inertia->render('Dashboard');
        $this->assertInstanceOf(Response::class, $response);
    }

    public function testViewDataSingle()
    {
        $this->inertia->viewData('app_name', 'Testing App 1');
        $this->inertia->viewData('app_version', '1.0.0');
        $this->assertEquals('Testing App 1', $this->inertia->getViewData('app_name'));
        $this->assertEquals('1.0.0', $this->inertia->getViewData('app_version'));
    }

    public function testViewDataMultiple()
    {
        $this->inertia->viewData('app_name', 'Testing App 2');
        $this->inertia->viewData('app_version', '2.0.0');
        $this->assertEquals(
            [
                'app_version' => '2.0.0',
                'app_name' => 'Testing App 2',
            ],
            $this->inertia->getViewData()
        );
    }

    public function testLazy()
    {
        $this->makeRequest();

        $this->assertInstanceOf(LazyProp::class, $this->inertia->lazy(fn() => null));

        $called = 0;
        $response = $this->inertia->share([
            'lazy' => $this->inertia->lazy(function () use (&$called) {
                $called++;
                return 'lazy';
            }),
            'eager' => fn() => 'eager',
            'normal' => 'normal',
        ])->render('Dashboard');

        $content = json_decode($response->getContent(), true)['props'];

        $this->assertArrayHasKey('normal', $content);
        $this->assertArrayHasKey('eager', $content);
        $this->assertEquals('eager', $content['eager']);
        $this->assertArrayNotHasKey('lazy', $content);
        $this->assertEquals(0, $called);
    }

    public function testLazyWithPartial()
    {
        $called = 0;

        $this->makeRequest(true, false, 'Dashboard', ['lazy']);

        $response = $this->inertia->share([
            'lazy' => $this->inertia->lazy(function () use (&$called) {
                $called++;
                return 'lazy';
            }),
            'eager' => fn() => 'eager',
            'normal' => 'normal',
        ])->render('Dashboard');

        $content = json_decode($response->getContent(), true)['props'];

        $this->assertArrayNotHasKey('normal', $content);
        $this->assertArrayNotHasKey('eager', $content);
        $this->assertArrayHasKey('lazy', $content);
        $this->assertEquals('lazy', $content['lazy']);
        $this->assertEquals(1, $called);
    }

    public function testContextSingle()
    {
        $this->inertia->context('groups', ['group1', 'group2']);
        $this->assertEquals(['group1', 'group2'], $this->inertia->getContext('groups'));
    }

    public function testContextMultiple()
    {
        $this->inertia->context('groups', ['group1', 'group2']);
        $this->assertEquals(
            [
                'groups' => ['group1', 'group2'],
            ],
            $this->inertia->getContext()
        );
    }

    public function testTypesArePreservedUsingJsonEncode()
    {
        $this->makeRequest();

        $this->inertia = new Inertia('app.twig.html', $this->environment, $this->requestStack, $this->serializer);

        $this->innerTestTypesArePreserved(false);
    }

    public function testTypesArePreservedUsingSerializer()
    {
        $this->makeRequest();

        $this->serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);
        $this->inertia = new Inertia('app.twig.html', $this->environment, $this->requestStack, $this->serializer);

        $this->innerTestTypesArePreserved(true);
    }

    private function innerTestTypesArePreserved($usingSerializer = false)
    {
        $props = [
            'integer' => 123,
            'float' => 1.23,
            'string' => 'test',
            'null' => null,
            'true' => true,
            'false' => false,
            'object' => new DateTime(),
            'empty_object' => new stdClass(),
            'iterable_object' => new ArrayObject([1, 2, 3]),
            'empty_iterable_object' => new ArrayObject(),
            'array' => [1, 2, 3],
            'empty_array' => [],
            'associative_array' => ['test' => 'test'],
        ];

        $response = $this->inertia->render('Dashboard', $props);
        $data = json_decode($response->getContent(), false);
        $responseProps = (array)$data->props;

        $this->assertIsInt($responseProps['integer']);
        $this->assertIsFloat($responseProps['float']);
        $this->assertIsString($responseProps['string']);
        $this->assertNull($responseProps['null']);
        $this->assertTrue($responseProps['true']);
        $this->assertFalse($responseProps['false']);
        $this->assertIsObject($responseProps['object']);
        $this->assertIsObject($responseProps['empty_object']);

        if (!$usingSerializer) {
            $this->assertIsObject($responseProps['iterable_object']);
        } else {
            $this->assertIsArray($responseProps['iterable_object']);
        }

        $this->assertIsObject($responseProps['empty_iterable_object']);
        $this->assertIsArray($responseProps['array']);
        $this->assertIsArray($responseProps['empty_array']);
        $this->assertIsObject($responseProps['associative_array']);
    }
}
