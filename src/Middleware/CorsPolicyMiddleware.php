<?php
declare(strict_types=1);

namespace Elephox\Miniphox\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class CorsPolicyMiddleware
{
    public function __construct(
        private readonly CorsOptions $options,
    )
    {
    }

    public function __invoke(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        if ($this->matches($request)) {
            return $this->handle($request);
        }

        return $next($request);
    }

    private function matches(ServerRequestInterface $request): bool
    {
        if (!$this->isCorsRequest($request)) {
            return false;
        }

        // TODO: check path, requested method, requested headers, origin

        return true;
    }

    private function isCorsRequest(ServerRequestInterface $request): bool
    {
        return strtolower($request->getMethod()) === 'options';
    }

    private function handle(ServerRequestInterface $request): ResponseInterface
    {
        // TODO: handle CORS request
    }
}
