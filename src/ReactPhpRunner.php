<?php
declare(strict_types=1);

namespace Elephox\Miniphox;

use Elephox\Collection\Contract\GenericSet;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Socket\SocketServer;
use Throwable;

trait ReactPhpRunner
{
    private string $host = "0.0.0.0";

    private int $port = 8008;

    private LoopInterface $loop;

    public function __construct()
    {
        $this->loop = Loop::get();
    }

    abstract public function getRouter(): Minirouter;

    abstract public function getLogger(): LoggerInterface;

    abstract public function getMiddlewares(): GenericSet;

    public function setHost(string $host): self
    {
        $this->host = $host;

        return $this;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function setPort(int $port): self
    {
        $this->port = $port;

        return $this;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    abstract public function handle(ServerRequestInterface $request): ResponseInterface;

    protected function beforeRequestHandling(ServerRequestInterface $request): ServerRequestInterface {
        return $request;
    }

    protected function beforeResponseSent(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
        return $response;
    }

    protected function afterResponseSent(ServerRequestInterface $request, ResponseInterface $response): void {}

    public function runReactServer(): int {
        $host = $this->getHost();
        $port = $this->getPort();

        $uri = "tcp://$host:$port";

        $socket = new SocketServer($uri);
        $socket->on('error', fn(Throwable $error) => $this->getLogger()->error($error));

        $http = new HttpServer(...$this->getMiddlewares()->append($this->reactPhpHandler(...))->toList());
        $http->on('error', fn(Throwable $error) => $this->getLogger()->error($error));
        $http->listen($socket);

        return 0;
    }

    private function reactPhpHandler(ServerRequestInterface $request): ResponseInterface
    {
        $request = $this->beforeRequestHandling($request);
        $response = $this->handle($request);
        $response = $this->beforeResponseSent($request, $response);

        $this->loop->futureTick(fn () => $this->afterResponseSent($request, $response));

        return $response;
    }
}
