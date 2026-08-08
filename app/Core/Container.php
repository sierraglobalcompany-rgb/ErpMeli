<?php

declare(strict_types=1);

namespace App\Core;

use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Contenedor deliberadamente pequeño: registra fábricas y resuelve constructores tipados.
 */
final class Container
{
    /** @var array<class-string|non-empty-string,callable(self):mixed> */
    private array $factories = [];
    /** @var array<class-string|non-empty-string,mixed> */
    private array $instances = [];

    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function instance(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances)
            || array_key_exists($id, $this->factories)
            || class_exists($id);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (isset($this->factories[$id])) {
            return $this->instances[$id] = ($this->factories[$id])($this);
        }
        if (!class_exists($id)) {
            throw new RuntimeException('Dependencia no registrada: ' . $id);
        }
        $reflection = new ReflectionClass($id);
        if (!$reflection->isInstantiable()) {
            throw new RuntimeException('La dependencia no se puede instanciar: ' . $id);
        }
        $constructor = $reflection->getConstructor();
        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return $reflection->newInstance();
        }
        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = $this->get($type->getName());
                continue;
            }
            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
                continue;
            }
            throw new RuntimeException('No se pudo resolver ' . $id . '::$' . $parameter->getName());
        }
        return $reflection->newInstanceArgs($arguments);
    }

    public static function application(): self
    {
        $container = new self();
        $container->instance(self::class, $container);
        return $container;
    }
}
