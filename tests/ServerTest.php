<?php

/*
 * This file is part of the Lepre package.
 *
 * (c) Daniele De Nobili <danieledenobili@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Lepre\Http\Server\Tests;

use Lepre\Http\Server\CallableMiddleware;
use Lepre\Http\Server\CallableRequestHandler;
use Lepre\Http\Server\Server;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Zend\Diactoros\Response;
use Zend\Diactoros\ServerRequest;

final class ServerTest extends TestCase
{
    public function testMiddleware()
    {
        $server = $this->createServer();
        $server->append($this->createCallableMiddleware());

        $this->assertResponse($this->serverHandle($server));
    }

    public function testCallableMiddleware()
    {
        $server = $this->createServer();
        $server->append($this->createClosureMiddleware());

        $this->assertResponse($this->serverHandle($server));
    }

    public function testServiceMiddleware()
    {
        // mock
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('has')
            ->with('middleware name')
            ->willReturn(true);
        $container->expects($this->once())
            ->method('get')
            ->with('middleware name')
            ->willReturn(
                $this->createCallableMiddleware()
            );

        // execution
        $server = $this->createServer(null, $container);
        $server->append('middleware name');

        // test
        $this->assertResponse($this->serverHandle($server));
    }

    public function testLazyServiceMiddleware()
    {
        // mock
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->never())->method('get');

        // execution
        $server = $this->createServer();
        $server->append('middleware name');
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Invalid middleware detected
     */
    public function testInvalidMiddlewareThrowsException()
    {
        $this->serverHandle(
            $this->createServer()->append('invalid middleware')
        );
    }

    /**
     * @param ServerRequestInterface $request
     * @param callable               $prepare
     * @param callable               $assertion
     *
     * @dataProvider appendProvider
     */
    public function testAppend(ServerRequestInterface $request, callable $prepare, callable $assertion)
    {
        // prepare
        $server = $this->createServer($this->createFinalRequestHandlerForTestOrderCall());
        $prepare($server);

        // execute
        $response = $server->handle($request);

        // test
        $assertion($response);
    }

    public function appendProvider()
    {
        $request = (new ServerRequest())->withHeader('X-Middleware', '');

        return [
            'priority' => [
                $request,
                function (Server $server) {
                    $server
                        ->append($this->createClosureMiddlewareForTestOrderCall('7'), 2)
                        ->append($this->createClosureMiddlewareForTestOrderCall('5'), 1)
                        ->append($this->createClosureMiddlewareForTestOrderCall('2'), -1)
                        ->append($this->createClosureMiddlewareForTestOrderCall('4'))
                        ->append($this->createClosureMiddlewareForTestOrderCall('3'), -1)
                        ->append($this->createClosureMiddlewareForTestOrderCall('1'), -2)
                        ->append($this->createClosureMiddlewareForTestOrderCall('6'), 1)
                        ->append($this->createClosureMiddlewareForTestOrderCall('8'), 2)
                    ;
                },
                function (ResponseInterface $response) {
                    $this->assertEquals('1,2,3,4,5,6,7,8,', $response->getHeaderLine('X-Middleware'));
                }
            ],
            'array or single middleware' => [
                $request,
                function (Server $server) {
                    $server
                        ->append($this->createClosureMiddlewareForTestOrderCall('8'), 2)
                        ->append([
                            $this->createClosureMiddlewareForTestOrderCall('3'),
                            $this->createClosureMiddlewareForTestOrderCall('4'),
                        ])
                        ->append([
                            $this->createClosureMiddlewareForTestOrderCall('1'),
                            $this->createClosureMiddlewareForTestOrderCall('2'),
                        ], -1)
                        ->append([
                            $this->createClosureMiddlewareForTestOrderCall('5'),
                            $this->createClosureMiddlewareForTestOrderCall('6'),
                            $this->createClosureMiddlewareForTestOrderCall('7'),
                        ], 1)
                    ;
                },
                function (ResponseInterface $response) {
                    $this->assertEquals('1,2,3,4,5,6,7,8,', $response->getHeaderLine('X-Middleware'));
                }
            ],
            'root' => [
                $request->withUri($request->getUri()->withPath('/')),
                function (Server $server) {
                    $server
                        ->append($this->createClosureMiddlewareForTestOrderCall(1), -1)
                        ->appendOnPath($this->createClosureMiddlewareForTestOrderCall(2), '/other-path')
                        ->appendOnPath($this->createClosureMiddlewareForTestOrderCall(3), '/', 1)
                        ->appendOnPath($this->createClosureMiddlewareForTestOrderCall(4), '/*') // supported, but the method append() is preferable
                        ->appendOnPath($this->createClosureMiddlewareForTestOrderCall(5), '*') // supported, but the method append() is preferable
                    ;
                },
                function (ResponseInterface $response) {
                    $this->assertEquals('1,4,5,3,', $response->getHeaderLine('X-Middleware'));
                }
            ],
            'append on path' => [
                $request->withUri($request->getUri()->withPath('/api/users')),
                function (Server $server) {
                    $server
                        ->appendOnPath($this->createClosureMiddlewareForTestOrderCall('1'), '/')
                        ->appendOnPath($this->createClosureMiddlewareForTestOrderCall('2'), '/api')
                        ->appendOnPath($this->createClosureMiddlewareForTestOrderCall('3'), '/api/')
                        ->appendOnPath($this->createClosureMiddlewareForTestOrderCall('4'), '/api*')
                        ->appendOnPath($this->createClosureMiddlewareForTestOrderCall('5'), '/api/*')
                        ->appendOnPath($this->createClosureMiddlewareForTestOrderCall('6'), '/login')
                        ->appendOnPath($this->createClosureMiddlewareForTestOrderCall('7'), '/a')
                        ->appendOnPath($this->createClosureMiddlewareForTestOrderCall('8'), '/a/')
                        ->appendOnPath($this->createClosureMiddlewareForTestOrderCall('9'), '/a*')
                        ->appendOnPath($this->createClosureMiddlewareForTestOrderCall('10'), '/a/*')
                        ->appendOnPath($this->createClosureMiddlewareForTestOrderCall('11'), '/*', -1) // supported, but the method append() is preferable
                        ->appendOnPath($this->createClosureMiddlewareForTestOrderCall('12'), '*', -1) // supported, but the method append() is preferable
                    ;
                },
                function (ResponseInterface $response) {
                    $this->assertEquals('11,12,4,5,9,', $response->getHeaderLine('X-Middleware'));
                }
            ],
        ];
    }

    /**
     * @return \Closure
     */
    public function createFinalRequestHandlerForTestOrderCall(): \Closure
    {
        return static function (ServerRequestInterface $request) {
            return (new Response())->withHeader(
                'X-Middleware',
                $request->getHeaderLine('X-Middleware')
            );
        };
    }

    /**
     * @return \Closure
     */
    private function createClosureMiddlewareForTestOrderCall($order): \Closure
    {
        return static function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($order) {
            $value = $request->getHeaderLine('X-Middleware') . $order . ',';

            return $handler->handle(
                $request->withHeader(
                    'X-Middleware',
                    $value
                )
            );
        };
    }

    public function testFinalHandler()
    {
        $server = $this->createServer(new CallableRequestHandler($this->createClosureRequestHandler()));

        $this->assertResponse($this->serverHandle($server));
    }

    public function testFinalCallable()
    {
        $server = $this->createServer($this->createClosureRequestHandler());

        $this->assertResponse($this->serverHandle($server));
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Invalid final request handler detected
     */
    public function testInvalidFinal()
    {
        $server = $this->createServer('invalid final');
        $this->serverHandle($server);
    }

    /**
     * @return void
     */
    private function assertResponse($value)
    {
        $this->assertInstanceOf(ResponseInterface::class, $value);
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject|ServerRequestInterface
     */
    private function mockRequest()
    {
        return $this->createMock(ServerRequestInterface::class);
    }

    /**
     * @return RequestHandlerInterface
     */
    private function createCallableRequestHandler(): RequestHandlerInterface
    {
        return new CallableRequestHandler($this->createClosureRequestHandler());
    }

    /**
     * @return \Closure
     */
    public function createClosureRequestHandler(): \Closure
    {
        return static function () {
            return (new Response())->withStatus(404);
        };
    }

    /**
     * @return MiddlewareInterface
     */
    private function createCallableMiddleware(): MiddlewareInterface
    {
        return new CallableMiddleware($this->createClosureMiddleware());
    }

    /**
     * @return \Closure
     */
    private function createClosureMiddleware(): \Closure
    {
        return static function (ServerRequestInterface $request, RequestHandlerInterface $handler) {
            return $handler->handle($request);
        };
    }

    /**
     * @param mixed $final
     * @param mixed $container
     * @return Server
     */
    private function createServer($final = null, $container = null)
    {
        if (!$final) {
            $final = $this->createCallableRequestHandler();
        }

        return new Server($final, $container);
    }

    /**
     * @param Server                 $server
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    private function serverHandle(Server $server, ServerRequestInterface $request = null)
    {
        if ($request === null) {
            $request = $this->mockRequest();
        }

        return $server->handle($request);
    }
}
