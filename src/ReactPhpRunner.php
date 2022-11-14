<?php
declare(strict_types=1);

namespace Elephox\Miniphox;

use Elephox\Collection\Contract\GenericSet;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use React\Http\HttpServer;
use React\Socket\SocketServer;
use Throwable;

trait ReactPhpRunner
{
    abstract public function getRouter(): Minirouter;

    abstract public function getLogger(): LoggerInterface;

    abstract public function getMiddlewares(): GenericSet;

    abstract public function getHost(): string;

    abstract public function getPort(): int;

    abstract public function handle(ServerRequestInterface $request): ResponseInterface;

    public function run(): int {
        $host = $this->getHost();
        $port = $this->getPort();

        $uri = "tcp://$host:$port";

        $socket = new SocketServer($uri);
        $socket->on('error', fn(Throwable $error) => $this->getLogger()->error($error));

        $http = new HttpServer(...$this->getMiddlewares()->append($this->handle(...))->toList());
        $http->on('error', fn(Throwable $error) => $this->getLogger()->error($error));
        $http->listen($socket);

        return 0;
    }
}
