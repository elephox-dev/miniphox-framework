<?php
declare(strict_types=1);

namespace Elephox\Miniphox\Middleware;

use Fruitcake\Cors\CorsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class CorsPolicyMiddleware
{
    public function __construct(
        private readonly CorsService $cors,
    )
    {
    }

    public function __invoke(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        // TODO: check if request path should be handled
        if (!$this->cors->isPreflightRequest($request)) {
            return $next($request);
        }

        return $this->cors->handlePreflightRequest($request);
    }
}
