<?php

declare(strict_types=1);

namespace EventCrew\Core;

use Closure;
use RuntimeException;

final class Container
{
    /**
     * @var array<string, Closure>
     */
    private array $bindings = [];

    /**
     * @var array<string, mixed>
     */
    private array $instances = [];

    public function singleton(string $id, Closure $factory): void
    {
        $this->bindings[$id] = $factory;
    }

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (! isset($this->bindings[$id])) {
            throw new RuntimeException(
                sprintf('Service "%s" has not been registered.', $id)
            );
        }

        $this->instances[$id] = ($this->bindings[$id])($this);

        return $this->instances[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->bindings[$id]);
    }

    /**
     * @return array<int, string>
     */
    public function registeredIds(): array
    {
        return array_keys($this->bindings);
    }
}
