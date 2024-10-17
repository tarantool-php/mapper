<?php

declare(strict_types=1);

namespace Tarantool\Mapper;

use Closure;
use LogicException;

class Pool
{
    use Api;

    private array $mappers = [];

    public function __construct(
        public readonly Closure $mapperFactory,
        public readonly ?Closure $spaceCasting = null
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

    public function getMapper(string $name): Mapper
    {
        if (!array_key_exists($name, $this->mappers)) {
            $callback = $this->mapperFactory;
            $this->mappers[$name] = $callback($name);
        }

        return $this->mappers[$name];
    }

    public function getSpace(object|int|string $name): Space
    {
        if (is_object($name) && $this->spaceCasting) {
            $callback = $this->spaceCasting;
            $name = $callback($name);
        }

        if (!is_string($name)) {
            throw new LogicException("Space should be a string");
        }

        [$mapper, $space] = explode('.', $name);
        return $this->getMapper($mapper)->getSpace($space);
    }
}
