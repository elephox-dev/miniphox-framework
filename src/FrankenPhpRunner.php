<?php
declare(strict_types=1);

namespace Elephox\Miniphox;

use Elephox\Http\ServerRequestBuilder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use function frankenphp_handle_request;

trait FrankenPhpRunner
{
    abstract public function handle(ServerRequestInterface $request): ResponseInterface;

    public function run(): int {
        do {
            $running = frankenphp_handle_request(function () {
                $request = ServerRequestBuilder::fromGlobals();
                $response = $this->handle($request);

                http_response_code($response->getStatusCode());

                foreach ($response->getHeaders() as $headerName => $values) {
                    header("$headerName: " . implode(',', $values));
                }

                echo $response->getBody()->getContents();
            });
        } while ($running);

        return 0;
    }
}
