<?php
declare(strict_types=1);

namespace Elephox\Miniphox;

use Elephox\Collection\Enumerable;
use Elephox\Collection\KeyedEnumerable;
use Elephox\Collection\KeyValuePair;
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
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use ReflectionAttribute;
use ReflectionException;
use ReflectionFunction;
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
    protected array $routingTable = [];
    public array $middlewares;
    public readonly ServiceCollection $services;

    protected function __construct(
        protected readonly string $appNamespace,
    )
    {
        $this->services = new ServiceCollectionImpl();
        $this->services->addSingleton(LoggerInterface::class, SingleSinkLogger::class, fn(): SingleSinkLogger => new SingleSinkLogger(new EnhancedMessageSink(new SimpleFormatColorSink(new StandardSink()))));

        $this->middlewares = [
            new StreamingRequestMiddleware(),
            new LimitConcurrentRequestsMiddleware(100),
            new RequestBodyParserMiddleware(),
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
        $this->debug("Creating cache of route handlers from declared functions.");

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

        $this->debug(sprintf("Mounting <gray>%s</gray> route(s) with base <gray>'%s'</gray>.", is_countable($routes) ? count($routes) : 'TBD', $base));

        $absolutePathParts = [];
        $pathParts = explode('/', $base);
        $parentRoutes = &$this->routingTable;
        foreach ($pathParts as $pathPart) {
            if (empty($pathPart)) {
                continue;
            }

            if (!isset($parentRoutes[$pathPart])) {
                $parentRoutes[$pathPart] = [self::METHODS_ROUTE_KEY => []];
            }

            $parentRoutes = &$parentRoutes[$pathPart];
            $absolutePathParts[] = $pathPart;
        }

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
                $attrReflections = $functionReflection->getAttributes(HttpMethodAttribute::class, ReflectionAttribute::IS_INSTANCEOF);

                if (empty($attrReflections)) {
                    $this->info("Function <green>{$functionReflection->getName()}</green> was mounted but has no HTTP attributes. Skipping.");

                    continue;
                }
            } catch (ReflectionException $re) {
                $this->error(sprintf("%s while accessing <green>%s</green>: %s", $re::class, is_string($route) ? ("function " . $route . "()") : $route, $re->getMessage()));

                continue;
            }

            foreach ($attrReflections as $attrReflection) {
                /** @var HttpMethodAttribute $attr */
                $attr = $attrReflection->newInstance();
                $path = $attr->path;
                $verb = $attr->verb;

                $destination = &$parentRoutes;
                if (empty($path)) {
                    $destinationPath = implode('/', $absolutePathParts);
                } else {
                    $pathPart = strtok($path, '/');
                    while ($pathPart !== false) {
                        $pathPart = self::normalizePathPart($pathPart);
                        if (!isset($destination[$pathPart])) {
                            $destination[$pathPart] = [self::METHODS_ROUTE_KEY => []];
                        }

                        $destinationPath = implode('/', [...$absolutePathParts, $pathPart]);
                        $destination = &$destination[$pathPart];
                        $pathPart = strtok('/');
                    }
                }

                if (isset($destination[self::METHODS_ROUTE_KEY][$verb])) {
                    $this->warning("Function <green>{$functionReflection->getName()}</green> overwrites handler for <magenta>$verb</magenta> at <gray>$destinationPath</gray>");
                }

                $destination[self::METHODS_ROUTE_KEY][$verb] = $functionReflection->getClosure();
            }
        }

        return $this;
    }

    public function run(string $uri = 'tcp://0.0.0.0:8008'): never
    {
        $socket = new SocketServer($uri);

        $socket->on('error', fn(Throwable $error) => $this->error($error));

        $httpUri = str_replace(['tcp', '0.0.0.0'], ['http', 'localhost'], $uri);
        $this->info("Starting HTTP server at <blue><underline>$httpUri</underline></blue>");

        $http = new HttpServer(
            ...$this->middlewares,
            $this->handle(...),
        );

        $http->on('error', fn(Throwable $error) => $this->error($error));
        $http->listen($socket);

        exit;
    }

    protected function handle(ServerRequestInterface $request): ResponseInterface
    {
        $routingTimer = -hrtime(true);

        $path = $request->getUri()->getPath();
        $routes = &$this->routingTable;

        $handlerArgs = [];

        $pathPart = strtok($path, '/');
        while ($pathPart !== false) {
            if (!isset($routes[$pathPart])) {
                $dynamicPathPart = KeyedEnumerable::from($routes)
                    ->where(fn(array $src, string $k) => str_starts_with($k, '[') && str_ends_with($k, ']'))
                    ->firstKeyOrDefault(null); // TODO: check if multiple dynamic routes exist and determine best fit
                if ($dynamicPathPart === null) {
                    return $this->handleNotFound($this->addPerformanceAttributes($request, $routingTimer));
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
            return $this->handleNotFound($this->addPerformanceAttributes($request, $routingTimer));
        }

        $availableMethods = $routes[self::METHODS_ROUTE_KEY];
        $method = $request->getMethod();
        if (!isset($availableMethods[$method])) {
            return $this->handleMethodNotAllowed($this->addPerformanceAttributes($request, $routingTimer));
        }

        $callback = $availableMethods[$method];

        try {
            $request = $this->addPerformanceAttributes($request, $routingTimer);
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

            $response = $this->addServerTimingHeaders($request, $response);

            return $this->logResponse($request, $response);
        } catch (Throwable $e) {
            $this->error($e);

            return $this->handleInternalServerError($request);
        }
    }

    protected function handleNotFound(ServerRequestInterface $request): ResponseInterface
    {
        $response = Response::json("Requested resource not found: {$request->getRequestTarget()}")
            ->withStatus(StatusCodeInterface::STATUS_NOT_FOUND);

        $response = $this->addServerTimingHeaders($request, $response);

        return $this->logResponse($request, $response);
    }

    protected function handleMethodNotAllowed(ServerRequestInterface $request): ResponseInterface
    {
        $response = Response::json("Method {$request->getMethod()} not allowed at: {$request->getRequestTarget()}")
            ->withStatus(StatusCodeInterface::STATUS_METHOD_NOT_ALLOWED);

        $response = $this->addServerTimingHeaders($request, $response);

        return $this->logResponse($request, $response);
    }

    protected function handleInternalServerError(ServerRequestInterface $request): ResponseInterface
    {
        $response = Response::plaintext("Unable to handle request.")
            ->withStatus(StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR);

        $response = $this->addServerTimingHeaders($request, $response);

        return $this->logResponse($request, $response);
    }

    protected function addPerformanceAttributes(ServerRequestInterface $request, float $routingTimer): ServerRequestInterface {
        return $request
            ->withAttribute('performance-timer-routing', ($routingTimer + hrtime(true)) / 1e+6)
            ->withAttribute('performance-timer-handling', -hrtime(true));
    }

    protected function addServerTimingHeaders(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $handlingTimer = $request->getAttribute('performance-timer-handling');
        $handlingTime = ($handlingTimer + hrtime(true)) / 1e+6; // ns to ms

        $routingTime = $request->getAttribute('performance-timer-routing');

        return $response
            ->withAddedHeader('Server-Timing', 'routing;desc="Request Routing";dur=' . $routingTime)
            ->withAddedHeader('Server-Timing', 'request-handler;desc="Request Callback Handling";dur=' . $handlingTime);
    }

    protected function logResponse(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $statusCode = $response->getStatusCode();
        $statusColor = match (true) {
            $statusCode < 300 => 'green',
            $statusCode < 400 => 'yellow',
            default => 'red',
        };

        $redirectInfo = '';
        if ($statusCode > 299 && $statusCode < 400) {
            $location = $response->getHeader('Location')[0];
            $redirectInfo = " -> <blue>$location</blue>";
        }

        // TODO: ...this is ugly.
        $routingTimingHeaderParts = explode(';', $response->getHeader('Server-Timing')[0]);
        $routingTimingHeaderDur = (float)substr(end($routingTimingHeaderParts), 4);
        $handlingTimingHeaderParts = explode(';', $response->getHeader('Server-Timing')[1]);
        $handlingTimingHeaderDur = (float) substr(end($handlingTimingHeaderParts), 4);

        $this->info(sprintf(
            '<blue>%s</blue> -> <%s>%d</%2$s>%s <gray>[r:%.3fms; h:%.3fms]</gray>',
            $request->getUri()->getPath(), $statusColor, $statusCode, $redirectInfo, $routingTimingHeaderDur, $handlingTimingHeaderDur
        ));

        return $response;
    }
}
