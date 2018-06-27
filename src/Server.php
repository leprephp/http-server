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

namespace Lepre\Http\Server;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Manages the stack of middleware and handles the server requests.
 *
 * @author Daniele De Nobili <danieledenobili@gmail.com>
 */
final class Server implements RequestHandlerInterface
{
    /**
     * @var RequestHandlerInterface
     */
    private $final;

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var array
     */
    private $stack = [];

    /**
     * @param callable|RequestHandlerInterface $final
     * @param ContainerInterface               $container
     */
    public function __construct($final, ContainerInterface $container = null)
    {
        $this->final = $final;
        $this->container = $container;
    }

    /**
     * Always adds a middleware to the stack.
     *
     * The middleware will run in any case.
     *
     * @param MiddlewareInterface|callable|array $middleware
     * @param int                                $priority
     * @return Server
     */
    public function append($middleware, $priority = 0): Server
    {
        return $this->appendOnCondition(
            $middleware,
            $this->buildAlwaysTrue(),
            $priority
        );
    }

    /**
     * Adds a middleware to the stack only if the request path matches.
     *
     * The path must match exactly. If the "*" character is present, a comparison will be
     * made via regex. For example: the path '/api' matches only with request path '/api'.
     * But the path '/api/*' matches with all request paths that start with '/api/'.
     *
     * The only position supported for the "*" character is at the end of the path. Other
     * positions may work today, but may no longer work in the future.
     *
     * The path '*' (or '/*') matches with all request path. For performance reasons, in
     * this case you can consider using the `append()` method instead.
     *
     * @param MiddlewareInterface|callable|array $middleware
     * @param string                             $path
     * @param int                                $priority
     * @return Server
     */
    public function appendOnPath($middleware, string $path, $priority = 0): Server
    {
        return $this->appendOnCondition(
            $middleware,
            $this->buildPathCondition($path),
            $priority
        );
    }

    /**
     * Adds a middleware to the stack only if the condition returns true.
     *
     * The `$condition` parameter must be a callable and it will be called with the
     * `ServerRequestInterface` object as argument.
     *
     * @param MiddlewareInterface|callable|array $middleware
     * @param callable                           $condition
     * @param int                                $priority
     * @return $this
     */
    public function appendOnCondition($middleware, callable $condition, $priority = 0): Server
    {
        if (!is_array($middleware)) {
            $middleware = [$middleware];
        }

        foreach ($middleware as $mw) {
            $this->stack[$priority][] = [$condition, $mw];
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return (new RequestHandler($this->buildStack($request), $this->final, $this->container))->handle($request);
    }

    /**
     * @return MiddlewareInterface[]
     */
    private function buildStack(ServerRequestInterface $request): array
    {
        ksort($this->stack);

        $stack = [];
        foreach ($this->stack as $priority => $mws) {
            foreach ($mws as $mw) {
                /** @var callable $condition */
                $condition = $mw[0];

                if ($condition($request)) {
                    $stack[] = $mw[1];
                }
            }
        }

        return $stack;
    }

    /**
     * @return \Closure
     */
    private function buildAlwaysTrue(): \Closure
    {
        return static function () {
            return true;
        };
    }

    /**
     * @param string $path
     * @return \Closure
     */
    private function buildPathCondition(string $path): \Closure
    {
        return static function (ServerRequestInterface $request) use ($path) {
            $regex = '/^' . str_replace('\*', '(.*?)', preg_quote($path, '/')) . '$/';

            return preg_match($regex, $request->getUri()->getPath());
        };
    }
}
