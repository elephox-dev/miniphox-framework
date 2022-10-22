<?php
declare(strict_types=1);

namespace Elephox\Miniphox;

use Closure;
use Elephox\Collection\Enumerable;
use Elephox\Collection\KeyedEnumerable;
use Elephox\DI\Contract\ServiceCollection;
use Elephox\DI\ServiceCollection as ServiceCollectionImpl;
use Elephox\Logging\EnhancedMessageSink;
use Elephox\Logging\LogLevelProxy;
use Elephox\Logging\SimpleFormatColorSink;
use Elephox\Logging\SingleSinkLogger;
use Elephox\Logging\StandardSink;
use Elephox\Miniphox\Attributes\HttpMethodAttribute;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyParserMiddleware;
use React\Socket\SocketServer;
use ReflectionAttribute;
use ReflectionException;
use ReflectionFunction;
use ricardoboss\Console;
use Stringable;
use Throwable;

class Miniphox
{
    use LogLevelProxy;

    public const DEFAULT_NAMESPACE = 'App';
    public const METHODS_ROUTE_KEY = '__METHOD__';

    public static function build(string $appNamespace = self::DEFAULT_NAMESPACE): self
    {
        return new self(self::normalizeAppNamespace($appNamespace));
    }

    protected static function normalizeAppNamespace(string $namespace): string
    {
        return strtolower(trim($namespace, '\\')) . '\\';
    }

    protected static function normalizePathPart(string $part): string
    {
        return trim($part, '/');
    }

    protected array $routeHandlerCache = [];
    protected array $routeMap = [];
    public array $middlewares;
    public readonly ServiceCollection $services;

    protected function __construct(
        protected readonly string $appNamespace,
    )
    {
        $this->services = new ServiceCollectionImpl();
        $this->services->addSingleton(LoggerInterface::class, SingleSinkLogger::class, fn(): SingleSinkLogger => new SingleSinkLogger(new EnhancedMessageSink(new SimpleFormatColorSink(new StandardSink()))));

        $this->middlewares = [
            new LimitConcurrentRequestsMiddleware(100),
            new RequestBodyParserMiddleware(),
            new RequestLoggerMiddleware($this->getLogger()),
        ];
    }

    protected function getLogger(): LoggerInterface
    {
        return $this->services->requireService(LoggerInterface::class);
    }

    protected function log(mixed $level, Stringable|string $message, array $context = []): void
    {
        $this->getLogger()->log($level, $message, $context);
    }

    protected function cacheDeclaredRouteHandlers(): void
    {
        /** @noinspection PotentialMalwareInspection */
        $this->routeHandlerCache = Enumerable::from(get_defined_functions()['user'])
            ->where(fn(string $fn): bool => str_starts_with($fn, $this->appNamespace))
            ->select(static fn(string $fn): ReflectionFunction => new ReflectionFunction($fn))
            ->where(static fn(ReflectionFunction $fn): bool => !empty($fn->getAttributes(HttpMethodAttribute::class, ReflectionAttribute::IS_INSTANCEOF)))
            ->toKeyed(static fn(ReflectionFunction $fn): string => $fn->getName())
            ->select(static fn(ReflectionFunction $fn): callable => $fn->getClosure())
            ->toArray();
    }

    protected function shouldRefreshDeclaredRouteHandlerCache(): bool
    {
        return empty($this->routeHandlerCache);
    }

    public function mount(string $base, iterable $routes): self
    {
        if ($this->shouldRefreshDeclaredRouteHandlerCache()) {
            $this->cacheDeclaredRouteHandlers();
        }

        $base = self::normalizePathPart($base);

        foreach ($routes as $route) {
            if (is_string($route)) {
                // qualify function name with app namespace so reflection works
                $route = $this->appNamespace . $route;
            } else if (!is_callable($route)) {
                $this->error(sprintf("Parameter <blue>\$routes</blue> may only contain callables or strings, <gray>%s</gray> given", get_debug_type($route)));

                continue;
            }

            try {
                $functionReflection = new ReflectionFunction($route);
            } catch (ReflectionException $re) {
                $this->error(sprintf("%s while accessing <green>%s</green>: %s", $re::class, is_string($route) ? ("function " . $route . "()") : $route, $re->getMessage()));

                continue;
            }

            $this->registerFunction($base, $functionReflection);
        }

        return $this;
    }

    protected function registerFunction(string $basePath, ReflectionFunction $functionReflection): void
    {
        $attributes = $functionReflection->getAttributes(HttpMethodAttribute::class, ReflectionAttribute::IS_INSTANCEOF);

        if (empty($attributes)) {
            $this->info("Function <green>{$functionReflection->getName()}</green> was mounted but has no HTTP method attributes.");

            return;
        }

        foreach ($attributes as $attribute) {
            $attributeInstance = $attribute->newInstance();

            assert($attributeInstance instanceof HttpMethodAttribute);

            $attributePath = '/' . $attributeInstance->path;
            $path = $basePath . $attributePath;
            $verb = $attributeInstance->verb;
            $closure = $functionReflection->getClosure();

            $success = $this->setVerbHandler($path, $verb, $closure, false);
            if (!$success) {
                $this->warning("Handler for <magenta>$verb</magenta> <blue>$path</blue> already exists. Skipping.");
            }
        }
    }

    protected function setVerbHandler(string $path, string $verb, Closure $closure, bool $overwrite): ?bool
    {
        $destinationRoute = &$this->routeMap;

        $pathPart = strtok($path, '/');
        while ($pathPart !== false) {
            $pathPart = self::normalizePathPart($pathPart);
            if (!isset($destinationRoute[$pathPart])) {
                $destinationRoute[$pathPart] = [self::METHODS_ROUTE_KEY => []];
            }

            $destinationRoute = &$destinationRoute[$pathPart];
            $pathPart = strtok('/');
        }

        assert(isset($destinationRoute[self::METHODS_ROUTE_KEY]));

        $exists = isset($destinationRoute[self::METHODS_ROUTE_KEY][$verb]);

        if ($exists) {
            if (!$overwrite) {
                return false; // handler for this route and verb already exists and should not be overwritten
            }

            $this->warning("Overwriting handler for <magenta>$verb</magenta> <blue>$path</blue>");
        }

        $destinationRoute[self::METHODS_ROUTE_KEY][$verb] = $closure;

        return true;
    }

    public function run(string $uri = 'tcp://0.0.0.0:8008'): never
    {
        $this->printRoutingTable();

        $socket = new SocketServer($uri);

        $socket->on('error', fn(Throwable $error) => $this->error($error));

        $httpUri = str_replace(['tcp', '0.0.0.0'], ['http', 'localhost'], $uri);
        $this->info("Running HTTP server at <blue><underline>$httpUri</underline></blue>");

        $http = new HttpServer(...[...$this->middlewares, $this->handle(...)]);

        $http->on('error', fn(Throwable $error) => $this->error($error));
        $http->listen($socket);

        exit;
    }

    protected function printRoutingTable(): void
    {
        $this->info("All available routes:");
        $table = [['Methods', 'Route']];
        $flattenMap = static function (array $map, array $path, callable $self) use (&$table): void {
            foreach ($map as $part => $row) {
                if ($part === self::METHODS_ROUTE_KEY && !empty($row)) {
                    $table[] = [
                        implode(", ", array_keys($row)),
                        Console::link(implode("/", $path)),
                    ];

                    continue;
                }

                $self($row, [...$path, $part], $self);
            }
        };

        $flattenMap($this->routeMap, [''], $flattenMap);

        foreach (Console::table($table, compact: true) as $line) {
            $this->info($line);
        }
    }

    protected function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $routes = &$this->routeMap;

        $handlerArgs = [];

        $pathPart = strtok($path, '/');
        while ($pathPart !== false) {
            if (!isset($routes[$pathPart])) {
                $dynamicPathPart = KeyedEnumerable::from($routes)
                    ->where(fn(array $src, string $k) => str_starts_with($k, '[') && str_ends_with($k, ']'))
                    ->firstKeyOrDefault(null); // TODO: check if multiple dynamic routes exist and determine best fit
                if ($dynamicPathPart === null) {
                    return $this->handleNotFound($request);
                }

                $routes = &$routes[$dynamicPathPart];

                $dynamicPartName = trim($dynamicPathPart, '[]');
                $handlerArgs[$dynamicPartName] = $pathPart;
            } else {
                $routes = &$routes[$pathPart];
            }

            // MAYBE: dynamic routes might contain / as part of their pattern and this should be ]/ instead
            $pathPart = strtok('/');
        }

        assert(is_array($routes), 'Invalid route table?');

        if (!isset($routes[self::METHODS_ROUTE_KEY])) {
            // no http handlers at this endpoint
            return $this->handleNotFound($request);
        }

        $availableMethods = $routes[self::METHODS_ROUTE_KEY];
        $method = $request->getMethod();
        if (!isset($availableMethods[$method])) {
            return $this->handleMethodNotAllowed($request);
        }

        $callback = $availableMethods[$method];

        try {
            $result = $this->services->callback($callback, ['request' => $request, ...$handlerArgs]);

            if (is_string($result)) {
                $response = Response::plaintext($result);
            } else if (is_array($result)) {
                $response = Response::json($result);
            } else if ($result instanceof ResponseInterface) {
                $response = $result;
            } else {
                $this->error(sprintf("Unable to infer response from type %s. Please return a string, array or instance of %s", get_debug_type($result), ResponseInterface::class));

                return $this->handleInternalServerError($request);
            }

            return $response;
        } catch (Throwable $e) {
            $this->error($e);

            return $this->handleInternalServerError($request);
        }
    }

    protected function handleNotFound(ServerRequestInterface $request): ResponseInterface
    {
        return Response::json("Requested resource not found: {$request->getRequestTarget()}")
            ->withStatus(StatusCodeInterface::STATUS_NOT_FOUND);
    }

    protected function handleMethodNotAllowed(ServerRequestInterface $request): ResponseInterface
    {
        return Response::json("Method {$request->getMethod()} not allowed at: {$request->getRequestTarget()}")
            ->withStatus(StatusCodeInterface::STATUS_METHOD_NOT_ALLOWED);
    }

    protected function handleInternalServerError(ServerRequestInterface $request): ResponseInterface
    {
        return Response::plaintext("Unable to handle request.")
            ->withStatus(StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR);
    }
}
