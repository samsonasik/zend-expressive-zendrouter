<?php
/**
 * @see       https://github.com/zendframework/zend-expressive-zendrouter for the canonical source repository
 * @copyright Copyright (c) 2015-2017 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive-zendrouter/blob/master/LICENSE.md New BSD License
 */

namespace ZendTest\Expressive\Router;

use Fig\Http\Message\RequestMethodInterface as RequestMethod;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Webimpress\HttpMiddlewareCompatibility\MiddlewareInterface;
use Zend\Diactoros\ServerRequest;
use Zend\Expressive\Router\Route;
use Zend\Expressive\Router\RouteResult;
use Zend\Expressive\Router\ZendRouter;
use Zend\Http\Request as ZendRequest;
use Zend\I18n\Translator\TranslatorInterface;
use Zend\Router\Http\TreeRouteStack;
use Zend\Router\RouteMatch;

class ZendRouterTest extends TestCase
{
    /** @var MiddlewareInterface */
    private $middleware;

    /** @var TreeRouteStack|ObjectProphecy */
    private $zendRouter;

    public function setUp()
    {
        $this->middleware = $this->prophesize(MiddlewareInterface::class)->reveal();
        $this->zendRouter = $this->prophesize(TreeRouteStack::class);
    }

    public function getRouter()
    {
        return new ZendRouter($this->zendRouter->reveal());
    }

    public function testWillLazyInstantiateAZendTreeRouteStackIfNoneIsProvidedToConstructor()
    {
        $router = new ZendRouter();
        $this->assertAttributeInstanceOf(TreeRouteStack::class, 'zendRouter', $router);
    }

    public function createRequestProphecy($requestMethod = RequestMethod::METHOD_GET)
    {
        $request = $this->prophesize(ServerRequestInterface::class);

        $uri = $this->prophesize(UriInterface::class);
        $uri->getPath()->willReturn('/foo');
        $uri->__toString()->willReturn('http://www.example.com/foo');

        $request->getMethod()->willReturn($requestMethod);
        $request->getUri()->will([$uri, 'reveal']);
        $request->getHeaders()->willReturn([]);
        $request->getCookieParams()->willReturn([]);
        $request->getQueryParams()->willReturn([]);
        $request->getServerParams()->willReturn([]);

        return $request;
    }

    public function testAddingRouteAggregatesInRouter()
    {
        $route = new Route('/foo', $this->middleware, ['GET']);
        $router = $this->getRouter();
        $router->addRoute($route);
        $this->assertAttributeContains($route, 'routesToInject', $router);
    }

    /**
     * @depends testAddingRouteAggregatesInRouter
     */
    public function testMatchingInjectsRoutesInRouter()
    {
        $route = new Route('/foo', $this->middleware, ['GET']);

        $this->zendRouter->addRoute('/foo^GET', [
            'type' => 'segment',
            'options' => [
                'route' => '/foo',
            ],
            'may_terminate' => false,
            'child_routes' => [
                'GET' => [
                    'type' => 'method',
                    'options' => [
                        'verb' => 'GET,HEAD,OPTIONS',
                        'defaults' => [
                            'middleware' => $this->middleware,
                        ],
                    ],
                ],
                ZendRouter::METHOD_NOT_ALLOWED_ROUTE => [
                    'type'     => 'regex',
                    'priority' => -1,
                    'options'  => [
                        'regex' => '',
                        'defaults' => [
                            ZendRouter::METHOD_NOT_ALLOWED_ROUTE => '/foo',
                        ],
                        'spec' => '',
                    ],
                ],
            ],
        ])->shouldBeCalled();

        $router = $this->getRouter();
        $router->addRoute($route);

        $request = $this->createRequestProphecy();
        $this->zendRouter->match(Argument::type(ZendRequest::class))->willReturn(null);

        $router->match($request->reveal());
    }

    /**
     * @depends testAddingRouteAggregatesInRouter
     */
    public function testGeneratingUriInjectsRoutesInRouter()
    {
        $route = new Route('/foo', $this->middleware, ['GET']);

        $this->zendRouter->addRoute('/foo^GET', [
            'type' => 'segment',
            'options' => [
                'route' => '/foo',
            ],
            'may_terminate' => false,
            'child_routes' => [
                'GET' => [
                    'type' => 'method',
                    'options' => [
                        'verb' => 'GET,HEAD,OPTIONS',
                        'defaults' => [
                            'middleware' => $this->middleware,
                        ],
                    ],
                ],
                ZendRouter::METHOD_NOT_ALLOWED_ROUTE => [
                    'type'     => 'regex',
                    'priority' => -1,
                    'options'  => [
                        'regex' => '',
                        'defaults' => [
                            ZendRouter::METHOD_NOT_ALLOWED_ROUTE => '/foo',
                        ],
                        'spec' => '',
                    ],
                ],
            ],
        ])->shouldBeCalled();
        $this->zendRouter->hasRoute('foo')->willReturn(true);
        $this->zendRouter->assemble(
            [],
            [
                'name' => 'foo',
                'only_return_path' => true,
            ]
        )->willReturn('/foo');

        $router = $this->getRouter();
        $router->addRoute($route);

        $this->assertEquals('/foo', $router->generateUri('foo'));
    }

    public function testCanSpecifyRouteOptions()
    {
        $route = new Route('/foo/:id', $this->middleware, ['GET']);
        $route->setOptions([
            'constraints' => [
                'id' => '\d+',
            ],
            'defaults' => [
                'bar' => 'baz',
            ],
        ]);

        $this->zendRouter->addRoute('/foo/:id^GET', [
            'type' => 'segment',
            'options' => [
                'route' => '/foo/:id',
                'constraints' => [
                    'id' => '\d+',
                ],
                'defaults' => [
                    'bar' => 'baz'
                ],
            ],
            'may_terminate' => false,
            'child_routes' => [
                'GET' => [
                    'type' => 'method',
                    'options' => [
                        'verb' => 'GET,HEAD,OPTIONS',
                        'defaults' => [
                            'middleware' => $this->middleware,
                        ],
                    ],
                ],
                ZendRouter::METHOD_NOT_ALLOWED_ROUTE => [
                    'type'     => 'regex',
                    'priority' => -1,
                    'options'  => [
                        'regex' => '',
                        'defaults' => [
                            ZendRouter::METHOD_NOT_ALLOWED_ROUTE => '/foo/:id',
                        ],
                        'spec' => '',
                    ],
                ],
            ],
        ])->shouldBeCalled();

        $this->zendRouter->hasRoute('foo')->willReturn(true);
        $this->zendRouter->assemble(
            [],
            [
                'name' => 'foo',
                'only_return_path' => true,
            ]
        )->willReturn('/foo');

        $router = $this->getRouter();
        $router->addRoute($route);
        $router->generateUri('foo');
    }

    public function routeResults()
    {
        $middleware = $this->prophesize(MiddlewareInterface::class)->reveal();
        return [
            'success' => [
                new Route('/foo', $middleware),
                RouteResult::fromRouteMatch('/foo', 'bar'),
            ],
            'failure' => [
                new Route('/foo', $middleware),
                RouteResult::fromRouteFailure(),
            ],
        ];
    }

    public function testMatch()
    {
        $middleware = $this->prophesize(MiddlewareInterface::class)->reveal();

        $route = new Route('/foo', $middleware, ['GET']);
        $zendRouter = new ZendRouter();
        $zendRouter->addRoute($route);

        $request = new ServerRequest([ 'REQUEST_METHOD' => 'GET' ], [], '/foo', 'GET');

        $result = $zendRouter->match($request);
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertEquals('/foo^GET', $result->getMatchedRouteName());
        $this->assertEquals($middleware, $result->getMatchedMiddleware());
    }

    /**
     * @group match
     */
    public function testSuccessfulMatchIsPossible()
    {
        $routeMatch = $this->prophesize(RouteMatch::class);
        $routeMatch->getMatchedRouteName()->willReturn('/foo');
        $routeMatch->getParams()->willReturn([
            'middleware' => 'bar',
        ]);

        $this->zendRouter
            ->match(Argument::type(ZendRequest::class))
            ->willReturn($routeMatch->reveal());
        $this->zendRouter
            ->addRoute('/foo', Argument::type('array'))
            ->shouldBeCalled();

        $request = $this->createRequestProphecy();

        $router = $this->getRouter();
        $router->addRoute(new Route('/foo', $this->middleware, [RequestMethod::METHOD_GET], '/foo'));
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('/foo', $result->getMatchedRouteName());
        $this->assertEquals($this->middleware, $result->getMatchedMiddleware());
    }

    /**
     * @group match
     */
    public function testNonSuccessfulMatchNotDueToHttpMethodsIsPossible()
    {
        $this->zendRouter
            ->match(Argument::type(ZendRequest::class))
            ->willReturn(null);

        $request = $this->createRequestProphecy();

        $router = $this->getRouter();
        $result = $router->match($request->reveal());
        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isMethodFailure());
    }

    /**
     * @group match
     */
    public function testMatchFailureDueToHttpMethodReturnsRouteResultWithAllowedMethods()
    {
        $router = new ZendRouter();
        $router->addRoute(new Route('/foo', $this->middleware, ['POST', 'DELETE']));
        $request = new ServerRequest([ 'REQUEST_METHOD' => 'GET' ], [], '/foo', 'GET');
        $result = $router->match($request);

        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isMethodFailure());
        $this->assertEquals(['POST', 'DELETE'], $result->getAllowedMethods());
    }

    /**
     * @group match
     */
    public function testMatchFailureDueToMethodNotAllowedWithParamsInTheRoute()
    {
        $router = new ZendRouter();
        $router->addRoute(new Route('/foo[/:id]', $this->middleware, ['POST', 'DELETE']));
        $request = new ServerRequest([ 'REQUEST_METHOD' => 'GET' ], [], '/foo/1', 'GET');
        $result = $router->match($request);

        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isFailure());
        $this->assertTrue($result->isMethodFailure());
        $this->assertEquals(['POST', 'DELETE'], $result->getAllowedMethods());
    }

    /**
     * @group 53
     */
    public function testCanGenerateUriFromRoutes()
    {
        $router = new ZendRouter();
        $route1 = new Route('/foo', $this->middleware, ['POST'], 'foo-create');
        $route2 = new Route('/foo', $this->middleware, ['GET'], 'foo-list');
        $route3 = new Route('/foo/:id', $this->middleware, ['GET'], 'foo');
        $route4 = new Route('/bar/:baz', $this->middleware, Route::HTTP_METHOD_ANY, 'bar');

        $router->addRoute($route1);
        $router->addRoute($route2);
        $router->addRoute($route3);
        $router->addRoute($route4);

        $this->assertEquals('/foo', $router->generateUri('foo-create'));
        $this->assertEquals('/foo', $router->generateUri('foo-list'));
        $this->assertEquals('/foo/bar', $router->generateUri('foo', ['id' => 'bar']));
        $this->assertEquals('/bar/BAZ', $router->generateUri('bar', ['baz' => 'BAZ']));
    }

    /**
     * @group 3
     */
    public function testPassingTrailingSlashToRouteNotExpectingItResultsIn404FailureRouteResult()
    {
        $router = new ZendRouter();
        $route  = new Route('/api/ping', $this->middleware, ['GET'], 'ping');

        $router->addRoute($route);
        $request = new ServerRequest([ 'REQUEST_METHOD' => 'GET' ], [], '/api/ping/', 'GET');
        $result = $router->match($request);
        $this->assertTrue($result->isFailure());
        $this->assertFalse($result->isMethodFailure());
    }

    public function testSuccessfulMatchingComposesRouteInRouteResult()
    {
        $route = new Route('/foo', $this->middleware, [RequestMethod::METHOD_GET]);

        $routeMatch = $this->prophesize(RouteMatch::class);
        $routeMatch->getMatchedRouteName()->willReturn($route->getName());
        $routeMatch->getParams()->willReturn([
            'middleware' => $route->getMiddleware(),
        ]);

        $this->zendRouter
            ->match(Argument::type(ZendRequest::class))
            ->willReturn($routeMatch->reveal());
        $this->zendRouter
            ->addRoute('/foo^GET', Argument::type('array'))
            ->shouldBeCalled();

        $request = $this->createRequestProphecy();

        $router = $this->getRouter();
        $router->addRoute($route);

        $result = $router->match($request->reveal());

        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertSame($route, $result->getMatchedRoute());
    }

    public function implicitMethods()
    {
        return [
            'head'    => [RequestMethod::METHOD_HEAD],
            'options' => [RequestMethod::METHOD_OPTIONS],
        ];
    }

    /**
     * @dataProvider implicitMethods
     *
     * @param string $method
     */
    public function testRoutesCanMatchImplicitHeadAndOptionsRequests($method)
    {
        $route = new Route('/foo', $this->middleware, [RequestMethod::METHOD_PUT]);

        $router = new ZendRouter();
        $router->addRoute($route);

        $request = $this->createRequestProphecy($method);
        $result = $router->match($request->reveal());

        $this->assertInstanceOf(RouteResult::class, $result);
        $this->assertTrue($result->isSuccess());
        $this->assertSame($route, $result->getMatchedRoute());
    }

    public function testUriGenerationMayUseOptions()
    {
        $route = new Route('/de/{lang}', $this->middleware, [RequestMethod::METHOD_PUT], 'test');

        $router = new ZendRouter();
        $router->addRoute($route);

        $translator = $this->prophesize(TranslatorInterface::class);
        $translator->translate('lang', 'uri', 'de')->willReturn('found');

        $uri = $router->generateUri('test', [], [
            'translator'  => $translator->reveal(),
            'locale'      => 'de',
            'text_domain' => 'uri',
        ]);

        $this->assertEquals('/de/found', $uri);
    }
}
