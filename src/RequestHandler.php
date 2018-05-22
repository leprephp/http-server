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
 * Handles the request and returns a response based on a stack of middleware.
 *
 * @author Daniele De Nobili <danieledenobili@gmail.com>
 */
class RequestHandler implements RequestHandlerInterface
{
    /**
     * @var MiddlewareInterface[]
     */
    private $stack;

    /**
     * @var RequestHandlerInterface
     */
    private $final;

    /**
     * @var int
     */
    private $index = 0;

    /**
     * @var ContainerInterface|null
     */
    private $container;

    /**
     * @param array                            $stack
     * @param callable|RequestHandlerInterface $final
     * @param ContainerInterface|null          $container
     */
    public function __construct(array $stack, $final, ContainerInterface $container = null)
    {
        $this->stack = $stack;
        $this->final = $final;
        $this->container = $container;
    }

    /**
     * @inheritDoc
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (isset($this->stack[$this->index])) {
            return $this->normalizeMiddleware($this->stack[$this->index])->process($request, $this->nextFrame());
        }

        return $this->getFinalHandler()->handle($request);
    }

    /**
     * @return RequestHandlerInterface
     */
    private function nextFrame(): RequestHandlerInterface
    {
        $next = clone $this;
        $next->index++;

        return $next;
    }

    /**
     * @param callable|MiddlewareInterface $middleware
     * @return MiddlewareInterface
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    private function normalizeMiddleware($middleware): MiddlewareInterface
    {
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        } elseif (is_callable($middleware)) {
            return new CallableMiddleware($middleware);
        } elseif ($this->container && $this->container->has($middleware)) {
            return $this->container->get($middleware);
        }

        throw new \InvalidArgumentException('Invalid middleware detected');
    }

    /**
     * @return RequestHandlerInterface
     */
    private function getFinalHandler(): RequestHandlerInterface
    {
        if ($this->final instanceof RequestHandlerInterface) {
            return $this->final;
        }

        if (is_callable($this->final)) {
            return new CallableRequestHandler($this->final);
        }

        throw new \InvalidArgumentException('Invalid final request handler detected');
    }
}
