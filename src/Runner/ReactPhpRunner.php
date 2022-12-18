<?php
declare(strict_types=1);

namespace Elephox\Miniphox\Runner;

use Elephox\Collection\ArraySet;
use Elephox\DI\Contract\ServiceCollection;
use Elephox\Miniphox\Middleware\InvokableMiddleware;
use Elephox\Miniphox\Minirouter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyParserMiddleware;
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

    abstract public function getServices(): ServiceCollection;

    /** @return ArraySet<MiddlewareInterface> */
    abstract public function getMiddlewares(): ArraySet;

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

    abstract protected function beforeRequestHandling(ServerRequestInterface $request): ServerRequestInterface;

    abstract protected function beforeResponseSent(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface;

    abstract protected function afterResponseSent(ServerRequestInterface $request, ResponseInterface $response): void;

    public function runReactServer(): int {
        $host = $this->getHost();
        $port = $this->getPort();

        $uri = "tcp://$host:$port";

        $socket = new SocketServer($uri);
        $socket->on('error', fn(Throwable $error) => $this->getLogger()->error($error));

        $middlewares = $this->getMiddlewares()
            ->select(static fn (MiddlewareInterface $m) => new InvokableMiddleware($m))
            ->prependAll([
                new LimitConcurrentRequestsMiddleware(100),
                new RequestBodyParserMiddleware(),
            ])
            ->append($this->reactPhpHandler(...))
            ->toList();

        $http = new HttpServer(...$middlewares);
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
