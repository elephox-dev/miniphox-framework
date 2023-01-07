<?php
declare(strict_types=1);

namespace Elephox\Miniphox\Services;

use Elephox\Collection\AmbiguousMatchException;
use Elephox\Collection\ArrayMap;
use Elephox\Collection\Contract\GenericKeyedEnumerable;
use Elephox\Collection\Contract\GenericMap;
use Elephox\DI\Contract\Resolver;
use Elephox\DI\UnresolvedParameterException;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

class DtoResolverService
{
    /** @var GenericMap<class-string> $dtos */
    protected GenericMap $dtos;

    /**
     * @param GenericMap<class-string, callable>|null $dtos
     */
    public function __construct(?GenericMap $dtos = null)
    {
        $this->dtos = $dtos ?? new ArrayMap();
    }

    /**
     * @param class-string $dtoClass
     * @param null|callable $factory
     */
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

    protected function getTypes(ReflectionParameter $parameter): GenericKeyedEnumerable {
        $type = $parameter->getType();
        if ($type instanceof ReflectionIntersectionType) {
            throw new LogicException(ReflectionIntersectionType::class . " parameters cannot be used as DTOs");
        }

        if ($type instanceof ReflectionNamedType) {
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

        return collect(...$types)->toArrayList();
    }

    public function getMatchingDtoFactory(ReflectionParameter $parameter): callable {
        $typeCollection = $this->getTypes($parameter);

        $possibleDtos = $this->dtos
            ->flip()
            ->where(fn (string $className) => $typeCollection->any(fn (ReflectionNamedType $type) => $type->getName() === $className))
            ->flip()
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

        return $possibleDtos->pop();
    }
}
