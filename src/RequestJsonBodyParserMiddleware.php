<?php
declare(strict_types=1);

namespace Elephox\Miniphox;

use Psr\Http\Message\ServerRequestInterface;
use function explode;
use function strtolower;

class RequestJsonBodyParserMiddleware
{
    public function __construct(
        private readonly bool $associative = true,
        private readonly int $maxDepth = 512,
        private readonly int $flags = JSON_THROW_ON_ERROR,
    )
    {
    }

    public function __invoke(ServerRequestInterface $request, $next)
    {
        $type = strtolower($request->getHeaderLine('Content-Type'));
        [$type] = explode(';', $type);

        if ($type === 'application/json') {
            return $next($this->parseJsonEncoded($request));
        }

        return $next($request);
    }

    private function parseJsonEncoded(ServerRequestInterface $request): ServerRequestInterface
    {
        $body = json_decode((string)$request->getBody(), $this->associative, $this->maxDepth, $this->flags);

        return $request->withParsedBody($body);
    }
}
