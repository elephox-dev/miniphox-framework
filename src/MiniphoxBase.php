<?php
declare(strict_types=1);

namespace Elephox\Miniphox;

use Elephox\Collection\ArraySet;
use Elephox\DI\Contract\ServiceCollection as ServiceCollectionContract;
use Elephox\DI\ServiceCollection;
use Elephox\Http\Response;
use Elephox\Http\ResponseCode;
use Elephox\Logging\EnhancedMessageSink;
use Elephox\Logging\SimpleFormatColorSink;
use Elephox\Logging\SingleSinkLogger;
use Elephox\Logging\StandardSink;
use Elephox\Miniphox\Middleware\CorsOptions;
use Elephox\Miniphox\Middleware\CorsPolicyMiddleware;
use Elephox\Miniphox\Middleware\RequestJsonBodyParserMiddleware;
use Elephox\Miniphox\Middleware\RequestLoggerMiddleware;
use Elephox\Miniphox\Middleware\StaticFileServerMiddleware;
use Elephox\OOR\Casing;
use Fruitcake\Cors\CorsService;
use JetBrains\PhpStorm\ArrayShape;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * @psalm-consistent-constructor
 */
class MiniphoxBase implements LoggerAwareInterface, RequestHandlerInterface, ResponseFactoryInterface
{
    public const DEFAULT_NAMESPACE = 'App';

    public static function build(): static
    {
        return new static(self::DEFAULT_NAMESPACE, null, null);
    }

    protected static function normalizeNamespace(string $namespace): string
    {
        return Casing::toLower(trim($namespace, '\\')) . '\\';
    }

    private readonly ServiceCollectionContract $services;
    private readonly ArraySet $middlewares;

    public function __construct(string $routesNamespace, ?ServiceCollectionContract $services, ?ArraySet $middlewares)
    {
        $routesNamespace = self::normalizeNamespace($routesNamespace);

        $this->services = $services ?? new ServiceCollection();
        $this->services->addSingleton(LoggerInterface::class, SingleSinkLogger::class, fn(): SingleSinkLogger => new SingleSinkLogger(new EnhancedMessageSink(new SimpleFormatColorSink(new StandardSink()))));
        $this->services->addSingleton(Minirouter::class, Minirouter::class, function (LoggerInterface $logger) use ($routesNamespace) {
            $router = new Minirouter($routesNamespace);
            $router->setLogger($logger);
            return $router;
        });

        $this->middlewares = $middlewares ?? new ArraySet();
        $this->middlewares->add(new RequestJsonBodyParserMiddleware());
    }

    public function getMiddlewares(): ArraySet
    {
        return $this->middlewares;
    }

    public function staticFiles(string $root): static
    {
        $this->middlewares->add(new StaticFileServerMiddleware($root));

        return $this;
    }

    public function cors(
        string|array $path,
        #[ArrayShape([
            'allowedMethods' => 'array',
            'allowedOrigins' => 'array',
            'allowedOriginsPatterns' => 'array',
            'allowedHeaders' => 'array',
            'exposedHeaders' => 'array',
            'maxAge' => 'int',
            'supportsCredentials' => 'bool'
        ])]
        array $options,
    ): static
    {
        if ($this->services->hasService(CorsService::class)) {
            $corsService = $this->services->requireService(CorsService::class);
        } else {
            $corsService = new CorsService($this, $options);
        }

        if (is_string($path)) {
            $path = [$path];
        }

        $this->middlewares->add(new CorsPolicyMiddleware($path, $corsService));

        $this->allowOptionsRequestMethod();

        return $this;
    }

    public function allowOptionsRequestMethod(): static {
        $this->getRouter()->setAllowOptionsRequests(true);

        return $this;
    }

    public function disallowOptionsRequestMethod(): static {
        $this->getRouter()->setAllowOptionsRequests(false);

        return $this;
    }

    public function logRequests(): static
    {
        $this->middlewares->add(new RequestLoggerMiddleware($this->services));

        return $this;
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
                $response = Response::build()->responseCode(ResponseCode::OK)->textBody($result)->get();
            } else if (is_array($result)) {
                $response = Response::build()->responseCode(ResponseCode::OK)->jsonBody($result)->get();
            } else if ($result instanceof ResponseInterface) {
                $response = $result;
            } else {
                throw new RuntimeException(sprintf("Unable to infer response from type %s. Please return a string, array or instance of %s", get_debug_type($result), ResponseInterface::class));
            }

            return $response;
        } catch (Throwable $e) {
            $this->getLogger()->error($e);

            return $this->handleInternalServerError($request, $e);
        }
    }

    protected function handleInternalServerError(ServerRequestInterface $request, ?Throwable $cause): ResponseInterface
    {
        return Response::build()
            ->responseCode(ResponseCode::InternalServerError)
            ->get();
    }

    protected function beforeRequestHandling(ServerRequestInterface $request): ServerRequestInterface {
        $this->services->addSingleton(ServerRequestInterface::class, instance: $request, replace: true);
        $this->services->addSingleton(RequestInterface::class, instance: $request, replace: true);

        return $request;
    }

    protected function beforeResponseSent(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
        return $response;
    }

    protected function afterResponseSent(ServerRequestInterface $request, ResponseInterface $response): void {
        $this->services->removeService(RequestInterface::class);
        $this->services->removeService(ServerRequestInterface::class);
    }

    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        return Response::build()->responseCode(ResponseCode::from($code))->get();
    }
}
