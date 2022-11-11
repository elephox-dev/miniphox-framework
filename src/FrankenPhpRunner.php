<?php
declare(strict_types=1);

namespace Elephox\Miniphox;

use Elephox\Http\ServerRequestBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

trait FrankenPhpRunner
{
    abstract public function handle(ServerRequestInterface $request): ResponseInterface;

    public function run(): int {
        do {
            $running = frankenphp_handle_request(function () {
                $request = ServerRequestBuilder::fromGlobals();
                $this->handle($request);
            });
        } while ($running);

        return 0;
    }
}