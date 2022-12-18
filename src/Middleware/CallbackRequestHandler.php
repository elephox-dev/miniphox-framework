<?php
declare(strict_types=1);

namespace Elephox\Miniphox\Middleware;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CallbackRequestHandler implements RequestHandlerInterface
{
    /**
     * @param Closure(ServerRequestInterface $request): ResponseInterface $next
     */
    public function __construct(private readonly Closure $next)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->next)($request);
    }
}
