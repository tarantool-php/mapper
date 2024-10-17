<?php

declare(strict_types=1);

namespace Tarantool\Mapper;

use Tarantool\Client\Schema\Criteria;
use Tarantool\Client\Schema\Operations;

trait Api
{
    abstract public function getSpace(object|int|string $id): Space;

    public function create(string $space, array $data)
    {
        return $this->getSpace($space)->create($data);
    }

    public function delete(object|string $space, array|object|null $instance = null)
    {
        if (is_object($space)) {
            $instance = $space;
        }
        $this->getSpace($space)->delete($instance);
    }

    public function find(string $space, Criteria|array|null $query = null): array
    {
        return $this->getSpace($space)->find($query);
    }

    public function findOne(string $space, Criteria|array|null $query = null)
    {
        return $this->getSpace($space)->findOne($query);
    }

    public function findOrCreate(string $space, array $query, ?array $params = null)
    {
        return $this->getSpace($space)->findOrCreate($query, $params);
    }

    public function findOrFail(string $space, Criteria|array|null $query = null)
    {
        return $this->getSpace($space)->findOrFail($query);
    }

    public function get(string $space, int $id, bool $require = true)
    {
        if ($require) {
            return $this->getSpace($space)->findOrFail(compact('id'));
        }

        return $this->getSpace($space)->findOne(compact('id'));
    }

    public function update(string|object $space, object|array $instance, Operations|array|null $operations = null)
    {
        if (is_object($space)) {
            [$instance, $operations] = [$space, $instance];
        }
        $this->getSpace($space)->update($instance, $operations);
    }
}
