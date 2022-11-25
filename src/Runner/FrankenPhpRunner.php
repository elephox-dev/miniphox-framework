<?php
/** @noinspection PhpUndefinedFunctionInspection */
declare(strict_types=1);

namespace Elephox\Miniphox\Runner;

use Elephox\Http\ServerRequestBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use function frankenphp_handle_request;

trait FrankenPhpRunner
{
    abstract public function handle(ServerRequestInterface $request): ResponseInterface;

    abstract protected function beforeRequestHandling(ServerRequestInterface $request): ServerRequestInterface;

    abstract protected function beforeResponseSent(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface;

    abstract protected function afterResponseSent(ServerRequestInterface $request, ResponseInterface $response): void;

    public function runFrankenPhpServer(): int {
        do {
            $running = frankenphp_handle_request(function () {
                $request = ServerRequestBuilder::fromGlobals();
                $request = $this->beforeRequestHandling($request);
                $response = $this->handle($request);
                $response = $this->beforeResponseSent($request, $response);

                http_response_code($response->getStatusCode());

                foreach ($response->getHeaders() as $headerName => $values) {
                    header("$headerName: " . implode(',', $values));
                }

                echo $response->getBody()->getContents();

                $this->afterResponseSent($request, $response);
            });
        } while ($running);

        return 0;
    }
}
