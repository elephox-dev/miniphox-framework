<?php
declare(strict_types=1);

namespace Elephox\Miniphox\Middleware;

use Elephox\OOR\Str;
use Fruitcake\Cors\CorsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CorsPolicyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly array $paths,
        private readonly CorsService $cors,
    )
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->shouldRun($request)) {
            return $handler->handle($request);
        }

        if ($this->cors->isPreflightRequest($request)) {
            return $this->cors->varyHeader(
                $this->cors->handlePreflightRequest($request),
                'Access-Control-Request-Method',
            );
        }

        $response = $handler->handle($request);

        if ($request->getMethod() === 'OPTIONS') {
            $response = $this->cors->varyHeader($response, 'Access-Control-Request-Method');
        }

        return $this->addCorsHeaders($request, $response);
    }

    private function addCorsHeaders(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
        if (!$response->hasHeader('Access-Control-Allow-Origin')) {
            $response = $this->cors->addActualRequestHeaders($response, $request);
        }

        return $response;
    }

    private function shouldRun(ServerRequestInterface $request): bool
    {
        foreach ($this->paths as $path) {
            if ($this->matches($request->getUri(), $path)) {
                return true;
            }
        }

        return false;
    }

    private function matches(UriInterface $uri, string $path):bool
    {
        $requestPath = $uri->getPath();

        if ($path === $requestPath) {
            return true;
        }

        return Str::is($path, $requestPath);
    }
}
