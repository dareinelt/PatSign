<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use RuntimeException;

final class Container
{
    /** @var array<string,mixed> */
    private array $instances = [];

    /** @var array<string,Closure(self):mixed> */
    private array $factories = [];

    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function singleton(string $id, Closure $factory): void
    {
        $this->set($id, function (self $container) use ($id, $factory): mixed {
            if (!array_key_exists($id, $this->instances)) {
                $this->instances[$id] = $factory($container);
            }
            return $this->instances[$id];
        });
    }

    public function get(string $id): mixed
    {
        if (!array_key_exists($id, $this->factories)) {
            throw new RuntimeException("Service {$id} is not registered.");
        }

        return ($this->factories[$id])($this);
    }
}
