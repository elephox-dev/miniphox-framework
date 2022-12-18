<?php
declare(strict_types=1);

namespace Elephox\Miniphox\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;

class InvokableMiddleware
{
    public function __construct(private readonly MiddlewareInterface $middleware)
    {
    }

    public function __invoke(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        return $this->middleware->process($request, new CallbackRequestHandler($next));
    }
}
