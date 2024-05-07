<?php

declare(strict_types=1);

namespace Tarantool\Mapper;

use Closure;

class Pool extends Api
{
    private array $mappers = [];

    public function __construct(
        public readonly Closure $callback
    ) {
    }

    public function flushChanges(): void
    {
        foreach ($this->mappers as $mapper) {
            $mapper->flushChanges();
        }
    }

    public function getChanges(): array
    {
        $changes = [];
        foreach ($this->mappers as $prefix => $mapper) {
            foreach ($mapper->getChanges() as $change) {
                $change->space = $prefix . '.' . $change->space;
                $changes[] = $change;
            }
        }

        return $changes;
    }

    public function getSpace(string $name): Space
    {
        [$mapper, $space] = explode('.', $name);
        if (!array_key_exists($mapper, $this->mappers)) {
            $callback = $this->callback;
            $this->mappers[$mapper] = $callback($mapper);
        }
        return $this->mappers[$mapper]->getSpace($space);
    }
}
