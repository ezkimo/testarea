<?php

declare(strict_types=1);

namespace MMNewmedia\Testarea\Container;

use ArrayObject;
use MMNewmedia\Testarea\Container\Exception;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * PSR-11 compliant lazy object service container
 * 
 * @author Marcel Maaß <marcel@mm-newmedia.de>
 * @since 2025-12-26
 */

final class ServiceContainer implements ContainerInterface
{
    public function __construct(
        protected ArrayObject $instances = new ArrayObject(),
        protected ArrayObject $reflectionCache = new ArrayObject(),
    ) {}

    public function set(string $class, callable|string $callback): void
    {
        $initializer = $callback;

        if (is_string($callback)) {
            if (class_exists($callback) === false) {
                throw new Exception\ContainerException('The given callback "%s" does not exist.');
            }
            
            $initializer = function(object $instance) use ($callback): object {
                $this->instances[$instance::class] = (new $callback())($this);
                return $this->instances[$instance::class];
            };
        }

        if (is_callable($callback) === true) {
            $initializer = function(object $instance) use ($callback): object {
                $this->instances[$instance::class] = $callback($this);
                return $this->instances[$instance::class];
            };
        }

        $this->instances[$class] = $this->getReflectionClass($class)->newLazyProxy($initializer);
    }

    public function get(string $id): object
    {
        if ($this->has($id) === true) {
            return $this->instances->offsetGet($id);
        }

        if (! class_exists($id)) {
            throw new Exception\NotFoundException(sprintf('Class %s not found.', $id));
        }

        $reflector = $this->getReflectionClass($id);

        if ($reflector->isInstantiable() === false) {
            throw new Exception\ContainerException('Class %s can not be initialized.');
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            $this->set($id, function() use ($id) {
                return new $id();
            });

            return $this->instances->offsetGet($id);
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                throw new Exception\ContainerException(sprintf(
                    'Can not resolve parameter $%s of constructor of class %s.',
                    $parameter->getName(),
                    $id
                ));
            }

            $arguments[] = $this->get($type->getName());
        }

        $this->set($id, function() use($reflector, $arguments) {
            return $reflector->newInstanceArgs($arguments);
        });

        return $this->instances->offsetGet($id);
    }

    public function has(string $id): bool
    {
        return $this->instances->offsetExists($id);
    }

    protected function getReflectionClass(string $class): ReflectionClass
    {
        if ($this->reflectionCache->offsetExists($class) === false) {
            $this->reflectionCache->offsetSet($class, new ReflectionClass($class));
        }

        return $this->reflectionCache->offsetGet($class);
    }
}