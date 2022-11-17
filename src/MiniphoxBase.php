<?php
declare(strict_types=1);

namespace Elephox\Miniphox;

use Elephox\Collection\ArraySet;
use Elephox\DI\Contract\ServiceCollection as ServiceCollectionContract;
use Elephox\DI\ServiceCollection;
use Elephox\Logging\EnhancedMessageSink;
use Elephox\Logging\SimpleFormatColorSink;
use Elephox\Logging\SingleSinkLogger;
use Elephox\Logging\StandardSink;
use Elephox\OOR\Casing;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use React\Http\Message\Response;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyParserMiddleware;
use Stringable;
use Throwable;

/**
 * @psalm-consistent-constructor
 */
class MiniphoxBase implements LoggerAwareInterface, RequestHandlerInterface
{
    public const DEFAULT_NAMESPACE = 'App';

    public static function build(): static
    {
        return new static(self::DEFAULT_NAMESPACE, null);
    }

    protected static function normalizeNamespace(string $namespace): string
    {
        return Casing::toLower(trim($namespace, '\\')) . '\\';
    }

    private readonly ArraySet $middlewares;
    private readonly ServiceCollectionContract $services;

    public function __construct(string $routesNamespace, ?ServiceCollectionContract $services)
    {
        $routesNamespace = self::normalizeNamespace($routesNamespace);
        $this->services = $services ?? new ServiceCollection();
        $this->services->addSingleton(LoggerInterface::class, SingleSinkLogger::class, fn(): SingleSinkLogger => new SingleSinkLogger(new EnhancedMessageSink(new SimpleFormatColorSink(new StandardSink()))));
        $this->services->addSingleton(Minirouter::class, Minirouter::class, function (LoggerInterface $logger) use ($routesNamespace) {
            $router = new Minirouter($routesNamespace);
            $router->setLogger($logger);
            return $router;
        });

        $this->middlewares = new ArraySet([
            new LimitConcurrentRequestsMiddleware(100),
            new RequestBodyParserMiddleware(),
            new RequestJsonBodyParserMiddleware(),
            new RequestLoggerMiddleware($this->services),
        ]);
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->services->addSingleton(LoggerInterface::class, instance: $logger, replace: true);
    }

    protected function getLogger(): LoggerInterface
    {
        return $this->services->requireService(LoggerInterface::class);
    }

    protected function getRouter(): Minirouter
    {
        return $this->services->requireService(Minirouter::class);
    }

    public function getServices(): ServiceCollectionContract
    {
        return $this->services;
    }

    public function getMiddlewares(): ArraySet
    {
        return $this->middlewares;
    }

    protected function log(mixed $level, Stringable|string $message, array $context = []): void
    {
        $this->getLogger()->log($level, $message, $context);
    }

    public function mount(string $base, callable|string ...$routes): static
    {
        $this->getRouter()->mount($base, $routes);

        return $this;
    }

    public function mountController(string $base, string $controller): static
    {
        $this->getRouter()->mountController($base, $controller);

        return $this;
    }

    public function registerDto(string $dtoClass, ?callable $factory = null): static
    {
        $this->getRouter()->registerDto($dtoClass, $factory);

        return $this;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $callback = $this->getRouter()->getHandler($request, $this->services);
            $result = $callback();

            if (is_string($result)) {
                $response = Response::plaintext($result);
            } else if (is_array($result)) {
                $response = Response::json($result);
            } else if ($result instanceof ResponseInterface) {
                $response = $result;
            } else {
                $this->getLogger()->error(sprintf("Unable to infer response from type %s. Please return a string, array or instance of %s", get_debug_type($result), ResponseInterface::class));

                return $this->handleInternalServerError($request);
            }

            return $response;
        } catch (Throwable $e) {
            $this->getLogger()->error($e);

            return $this->handleInternalServerError($request);
        }
    }

    protected function handleInternalServerError(ServerRequestInterface $request): ResponseInterface
    {
        return Response::plaintext("Unable to handle request.")
            ->withStatus(StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR);
    }

    protected function beforeRequestHandling(ServerRequestInterface $request): ServerRequestInterface {
        return $request;
    }

    protected function beforeResponseSent(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
        return $response;
    }

    protected function afterResponseSent(ServerRequestInterface $request, ResponseInterface $response): void {
    }
}
