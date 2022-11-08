<?php
declare(strict_types=1);

namespace Elephox\Miniphox;

use Closure;
use Elephox\Collection\AmbiguousMatchException;
use Elephox\Collection\ArrayMap;
use Elephox\Collection\Contract\GenericMap;
use Elephox\Collection\KeyedEnumerable;
use Elephox\DI\Contract\Resolver;
use Elephox\DI\Contract\ServiceCollection;
use Elephox\DI\UnresolvedParameterException;
use Elephox\Http\RequestMethod;
use Elephox\Web\Routing\Attribute\Contract\RouteAttribute;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use React\Http\Message\Response;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use ricardoboss\Console;

class Minirouter
{
    public const METHODS_ROUTE_KEY = '__METHOD__';

    protected static function normalizePathPart(string $part): string
    {
        return trim($part, '/');
    }

    protected array $routeMap = [self::METHODS_ROUTE_KEY => []];
    protected GenericMap $dtos;

    public function __construct(
        protected readonly string $appNamespace,
    )
    {
        $this->dtos = new ArrayMap();
    }

    public function mount(string $base, iterable $routes, LoggerInterface $logger): void
    {
        $base = self::normalizePathPart($base);

        foreach ($routes as $route) {
            if ($route instanceof ReflectionFunctionAbstract) {
                $functionReflection = $route;
            } else {
                if (is_string($route)) {
                    // qualify function name with app namespace so reflection works
                    $route = $this->appNamespace . $route;
                } else if (!is_callable($route)) {
                    $logger->error(sprintf("Parameter <blue>\$routes</blue> may only contain callables or strings, <gray>%s</gray> given", get_debug_type($route)));

                    continue;
                }

                try {
                    $functionReflection = new ReflectionFunction($route);
                } catch (ReflectionException $re) {
                    $logger->error(sprintf("%s while accessing <green>%s</green>: %s", $re::class, is_string($route) ? ("function " . $route . "()") : $route, $re->getMessage()));

                    continue;
                }
            }

            $this->registerFunction($base, $functionReflection, $logger);
        }
    }

    public function mountController(string $base, string $class, LoggerInterface $logger): void
    {
        $base = self::normalizePathPart($base);

        try {
            $classReflection = new ReflectionClass($class);
            $methods = array_filter($classReflection->getMethods(ReflectionMethod::IS_PUBLIC), static fn (ReflectionMethod $m) => !$m->isConstructor() && !$m->isDestructor());
        } catch (ReflectionException $re) {
            $logger->error(sprintf("%s while accessing class <green>%s</green>: %s", $re::class, $class, $re->getMessage()));

            return;
        }

        $this->mount($base, $methods, $logger);
    }

    public function registerDto(string $dtoClass, ?callable $factory = null): void {
        $factory ??= static function (ServerRequestInterface $request, array $routeParams, Resolver $resolver) use ($dtoClass) {
            $body = $request->getParsedBody();
            if (!is_array($body)) {
                $body = [];
            }

            return $resolver->instantiate($dtoClass, ['request' => $request, 'routeParams' => $routeParams, ...$routeParams, ...$body]);
        };

        $this->dtos->put($dtoClass, $factory);
    }

    protected function registerFunction(string $basePath, ReflectionFunctionAbstract $functionReflection, LoggerInterface $logger): void
    {
        $attributes = $functionReflection->getAttributes(RouteAttribute::class, ReflectionAttribute::IS_INSTANCEOF);

        if (empty($attributes)) {
            $logger->info("Function <green>{$functionReflection->getName()}</green> was mounted but has no HTTP method attributes.");

            return;
        }

        foreach ($attributes as $attribute) {
            $attributeInstance = $attribute->newInstance();

            assert($attributeInstance instanceof RouteAttribute);

            $attributePath = '/' . $attributeInstance->getPath();
            $path = $basePath . $attributePath;
            $verbs = $attributeInstance->getRequestMethods();

            if ($functionReflection instanceof ReflectionFunction || $functionReflection->isStatic()) {
                $closure = static fn () => $functionReflection->getClosure();
            } else {
                $closure = static function (ServiceCollection $services) use ($functionReflection): Closure {
                    assert($functionReflection instanceof ReflectionMethod);

                    $controllerClass = $functionReflection->getDeclaringClass();
                    $controllerClassName = $controllerClass->getName();

                    if (!$services->hasService($controllerClassName)) {
                        $services->addSingleton($controllerClassName, $controllerClassName);
                    }

                    $controller = $services->requireService($controllerClassName);
                    return $functionReflection->getClosure($controller);
                };
            }

            /** @var RequestMethod $verb */
            foreach ($verbs as $verb) {
                $success = $this->setVerbHandler($path, $verb->name, $closure, false, $logger);
                if (!$success) {
                    $logger->warning("Handler for <magenta>$verb->name</magenta> <blue>$path</blue> already exists. Skipping.");
                }
            }
        }
    }

    protected function setVerbHandler(string $path, string $verb, Closure $closure, bool $overwrite, LoggerInterface $logger): ?bool
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

            $logger->warning("Overwriting handler for <magenta>$verb</magenta> <blue>$path</blue>");
        }

        $destinationRoute[self::METHODS_ROUTE_KEY][$verb] = $closure;

        return true;
    }

    public function printRoutingTable(LoggerInterface $logger): void
    {
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

    public function getHandler(ServerRequestInterface $request, Resolver $services): callable
    {
        $routeParams = [];
        $path = $request->getUri()->getPath();
        $routes = &$this->routeMap;

        $pathPart = strtok($path, '/');
        while ($pathPart !== false) {
            if (!isset($routes[$pathPart])) {
                $dynamicPathPart = KeyedEnumerable::from($routes)
                    ->where(fn(array $src, string $k) => str_starts_with($k, '[') && str_ends_with($k, ']'))
                    ->firstKeyOrDefault(null); // TODO: check if multiple dynamic routes exist and determine best fit
                if ($dynamicPathPart === null) {
                    return fn () => $this->handleNotFound($request);
                }

                $routes = &$routes[$dynamicPathPart];

                $dynamicPartName = trim($dynamicPathPart, '[]');
                $routeParams[$dynamicPartName] = $pathPart;
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
        $callback = $services->callback($callbackFactory);

        if (RequestMethod::from($method)->canHaveBody()) {
            $body = $request->getParsedBody();
            if (is_array($body)) {
                $routeParams += $body;
            }
        }

        return fn () => $services->callback(
            $callback,
            $this->getHandlerArgs($request, $routeParams),
            fn (ReflectionParameter $parameter) => $services->callback(
                $this->resolveDynamicParameter(...),
                ['parameter' => $parameter, 'request' => $request, 'routeParams' => $routeParams],
            ),
        );
    }

    protected function getHandlerArgs(ServerRequestInterface $request, array $routeParams): array {
        return  ['request' => $request, 'routeParams' => $routeParams, ...$routeParams];
    }

    protected function resolveDynamicParameter(ReflectionParameter $parameter, Resolver $resolver, ServerRequestInterface $request, array $routeParams): mixed {
        $type = $parameter->getType();
        if ($type instanceof ReflectionIntersectionType) {
            assert(false, "ReflectionIntersectionTypes are not supported yet");
        } else if ($type instanceof ReflectionNamedType) {
            $types = [$type];
        } else if ($type instanceof ReflectionUnionType) {
            $types = $type->getTypes();
        } else {
            throw new UnresolvedParameterException(
                $parameter->getDeclaringClass()?->getName() ?? '<unknown>',
                $parameter->getDeclaringFunction()->getName(),
                $parameter->getType()?->getName() ?? 'mixed',
                $parameter->getName(),
            );
        }

        assert(!empty($types));

        $typeCollection = collect(...$types)->toArrayList();
        $possibleDtos = $this->dtos
            ->keys()
            ->where(fn (string $className) => $typeCollection->any(fn (ReflectionNamedType $type) => $type->getName() === $className))
            ->toArrayList();

        if ($possibleDtos->isEmpty()) {
            throw new UnresolvedParameterException(
                $parameter->getDeclaringClass()?->getName() ?? '<unknown>',
                $parameter->getDeclaringFunction()->getName(),
                $parameter->getType()?->getName() ?? 'mixed',
                $parameter->getName(),
            );
        }

        if ($possibleDtos->count() > 1) {
            throw new AmbiguousMatchException();
        }

        /** @var class-string $dtoClass */
        $dtoClass = $possibleDtos->pop();
        $factory = $this->dtos->get($dtoClass);

        return $resolver->callback($factory, $this->getHandlerArgs($request, $routeParams));
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
}
