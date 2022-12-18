<?php
declare(strict_types=1);

namespace Elephox\Miniphox\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use function explode;
use function strtolower;

class RequestJsonBodyParserMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly bool $associative = true,
        private readonly int $maxDepth = 512,
        private readonly int $flags = JSON_THROW_ON_ERROR,
    )
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $type = strtolower($request->getHeaderLine('Content-Type'));
        [$type] = explode(';', $type);

        if ($type === 'application/json') {
            return $handler->handle($this->parseJsonEncoded($request));
        }

        return $handler->handle($request);
    }

    private function parseJsonEncoded(ServerRequestInterface $request): ServerRequestInterface
    {
        $body = json_decode((string)$request->getBody(), $this->associative, $this->maxDepth, $this->flags);

        return $request->withParsedBody($body);
    }
}
