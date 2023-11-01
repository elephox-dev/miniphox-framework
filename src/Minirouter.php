<?php
declare(strict_types=1);

namespace Elephox\Miniphox;

use Closure;
use Elephox\Collection\KeyedEnumerable;
use Elephox\DI\Contract\Resolver;
use Elephox\DI\Contract\ServiceProvider;
use Elephox\DI\UnresolvedParameterException;
use Elephox\Http\RequestMethod;
use Elephox\Miniphox\Services\DtoResolverService;
use Elephox\Web\Routing\Attribute\Contract\ActionAttribute;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use React\Http\Message\Response;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionParameter;
use ricardoboss\Console;

class Minirouter implements LoggerAwareInterface
{
    public const METHODS_ROUTE_KEY = '__METHOD__';

    protected static function normalizePathPart(string $part): string
    {
        return trim($part, '/');
    }

    protected array $routeMap = [self::METHODS_ROUTE_KEY => []];
    protected LoggerInterface $logger;
    protected bool $allowOptionsRequests = false;

    public function __construct(
        protected readonly string $anonymousRoutesNamespace,
        protected readonly DtoResolverService $dtoResolverService,
    )
    {
    }

    public function setAllowOptionsRequests(bool $allow): void
    {
        $this->allowOptionsRequests = $allow;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function mount(string $base, iterable $routes): void
    {
        $base = self::normalizePathPart($base);

        foreach ($routes as $route) {
            if ($route instanceof ReflectionFunctionAbstract) {
                $functionReflection = $route;
            } else {
                if (is_string($route)) {
                    // qualify function name with app namespace so reflection works
                    $route = $this->anonymousRoutesNamespace . $route;
                } else if (!is_callable($route)) {
                    $this->logger->error(sprintf("Parameter <blue>\$routes</blue> may only contain callables or strings, <gray>%s</gray> given", get_debug_type($route)));

                    continue;
                }

                try {
                    $functionReflection = new ReflectionFunction($route);
                } catch (ReflectionException $re) {
                    $this->logger->error(sprintf("%s while accessing <green>%s</green>: %s", $re::class, is_string($route) ? ("function " . $route . "()") : $route, $re->getMessage()));

                    continue;
                }
            }

            $this->registerFunction($base, $functionReflection);
        }
    }

    public function mountController(string $base, string $class): void
    {
        $base = self::normalizePathPart($base);

        try {
            $classReflection = new ReflectionClass($class);
            $methods = array_filter($classReflection->getMethods(ReflectionMethod::IS_PUBLIC), static fn (ReflectionMethod $m) => !$m->isConstructor() && !$m->isDestructor());
        } catch (ReflectionException $re) {
            $this->logger->error(sprintf("%s while accessing class <green>%s</green>: %s", $re::class, $class, $re->getMessage()));

            return;
        }

        $this->mount($base, $methods);
    }

    public function registerDto(string $dtoClass, ?callable $factory = null): self {
        $this->dtoResolverService->registerDto($dtoClass, $factory);

        return $this;
    }

    protected function registerFunction(string $basePath, ReflectionFunctionAbstract $functionReflection): void
    {
        $attributes = $functionReflection->getAttributes(ActionAttribute::class, ReflectionAttribute::IS_INSTANCEOF);

        if (empty($attributes)) {
            $this->logger->info("Function <green>{$functionReflection->getName()}</green> was mounted but has no HTTP method attributes.");

            return;
        }

        foreach ($attributes as $attribute) {
            $attributeInstance = $attribute->newInstance();

            assert($attributeInstance instanceof ActionAttribute);

            $attributePath = '/' . $attributeInstance->getPath();
            $path = $basePath . $attributePath;
            $verbs = $attributeInstance->getRequestMethods();

            if ($functionReflection instanceof ReflectionFunction || $functionReflection->isStatic()) {
                $closure = static fn () => $functionReflection->getClosure();
            } else {
                $closure = static function (ServiceProvider $services) use ($functionReflection): Closure {
                    assert($functionReflection instanceof ReflectionMethod);

                    $controllerClass = $functionReflection->getDeclaringClass();
                    $controllerClassName = $controllerClass->getName();
                    $controller = $services->get(Resolver::class)->instantiate($controllerClassName);
                    return $functionReflection->getClosure($controller);
                };
            }

            /** @var RequestMethod $verb */
            foreach ($verbs as $verb) {
                $success = $this->setVerbHandler($path, $verb->name, $closure, false);
                if (!$success) {
                    $this->logger->warning("Handler for <magenta>$verb->name</magenta> <blue>$path</blue> already exists. Skipping.");
                }
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

            $this->logger->warning("Overwriting handler for <magenta>$verb</magenta> <blue>$path</blue>");
        }

        $destinationRoute[self::METHODS_ROUTE_KEY][$verb] = $closure;

        if ($this->allowOptionsRequests) {
            $destinationRoute[self::METHODS_ROUTE_KEY]['OPTIONS'] = $closure;
        }

        return true;
    }

    public function printRoutingTable(?LoggerInterface $logger = null): void
    {
        $logger ??= $this->logger;

        $logger->info("All available routes:");
        $table = [];
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

        foreach (Console::table($table, compact: true, headers: ['Methods', 'Route']) as $line) {
            $logger->info($line);
        }
    }

    public function getHandler(ServerRequestInterface $request, Resolver $resolver): callable
    {
        $routeParams = [];
        $path = $request->getUri()->getPath();
        $routes = &$this->routeMap;

        $pathPart = strtok($path, '/');
        while ($pathPart !== false) {
            if (!isset($routes[$pathPart])) {
                $found = false;
                foreach ($routes as $routePart => $subRoutes) {
                    if (str_starts_with($routePart, '{') && str_ends_with($routePart, '}')) {
                        $routes = &$subRoutes;
                        $routePartName = trim($routePart, '{}');
                        $routeParams[$routePartName] = $pathPart;

                        // TODO: check if multiple dynamic routes exist and determine best fit
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    return fn () => $this->handleNotFound($request);
                }
            } else {
                $routes = &$routes[$pathPart];
            }

            // MAYBE: dynamic routes might contain / as part of their pattern and this should be ]/ instead
            $pathPart = strtok('/');
        }

        assert(is_array($routes), 'Invalid route table?');

        if (!isset($routes[self::METHODS_ROUTE_KEY]) || empty($routes[self::METHODS_ROUTE_KEY])) {
            // no http handlers at this endpoint
            return fn () => $this->handleNotFound($request);
        }

        $availableMethods = $routes[self::METHODS_ROUTE_KEY];
        $method = $request->getMethod();
        if (!isset($availableMethods[$method])) {
            return fn () => $this->handleMethodNotAllowed($request);
        }

        $callbackFactory = $availableMethods[$method];
        $callback = $resolver->call($callbackFactory);

        if (RequestMethod::from($method)->canHaveBody()) {
            $body = $request->getParsedBody();
            if (is_array($body)) {
                $routeParams += $body;
            }
        }

        return function () use ($resolver, $callback, $request, $routeParams): mixed {
            $unresolvedDynamicParameter = false;
            $arguments = $resolver->resolveArguments(
                new ReflectionFunction($callback),
                $this->getHandlerArgs($request, $routeParams),
                function (ReflectionParameter $parameter) use ($resolver, $request, $routeParams, &$unresolvedDynamicParameter): mixed
                {
                    try {
                        $dtoFactory = $this->dtoResolverService->getMatchingDtoFactory($parameter);
                        $factoryArgs = $this->getHandlerArgs($request, $routeParams);
                        return $resolver->call($dtoFactory, $factoryArgs);
                    } catch (UnresolvedParameterException) {
                        $unresolvedDynamicParameter = true;

                        return null;
                    }
                },
            );

            if ($unresolvedDynamicParameter) {
                return $this->handleUnprocessableEntity($request);
            }

            return $callback(...$arguments);
        };
    }

    protected function getHandlerArgs(ServerRequestInterface $request, array $routeParams): array {
        return  ['request' => $request, 'routeParams' => $routeParams, ...$routeParams];
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

    protected function handleUnprocessableEntity(ServerRequestInterface $request): ResponseInterface
    {
        return Response::json(['message' => "Unable to processes request body", 'body' => $request->getParsedBody()])
            ->withStatus(StatusCodeInterface::STATUS_UNPROCESSABLE_ENTITY);
    }
}
