<?php

declare(strict_types=1);

namespace Tarantool\Mapper;

use Closure;
use Exception;
use Tarantool\Client\Schema\Criteria;
use Tarantool\Client\Schema\Space as ClientSpace;

class Schema
{
    public readonly Mapper $mapper;
    private array $meta = [];
    private array $spaces = [];

    public function __construct(Mapper $mapper, $meta = null)
    {
        $this->mapper = $mapper;
        if ($meta) {
            $this->setMeta($meta);
        } else {
            $this->reset();
        }
    }

    public function createSpace(string $space, array $config = []): Space
    {
        $options = [
            'engine' => 'memtx',
        ];

        foreach (['engine', 'is_local', 'temporary', 'is_sync', 'if_not_exists'] as $key) {
            if (array_key_exists($key, $config)) {
                $options[$key] = $config[$key];
            }
        }

        if (!in_array($options['engine'], ['memtx', 'vinyl'])) {
            throw new Exception("Invalid engine " . $options['engine']);
        }

        [$id] = $this->mapper->getClient()->evaluate("
            local space, options = ...
            box.schema.space.create(space, options)
            return box.space[space].id
        ", $space, $options);

        $this->spaces[$id] = new Space($this->mapper, [
            'id' => $id,
            'name' => $space,
            'engine' => $options['engine'],
            'format' => [],
        ]);

        $properties = array_key_exists('properties', $config) ? $config['properties'] : $config;

        if ($properties) {
            $this->spaces[$id]->addProperties($properties);
        }

        $this->meta[$id] = $this->spaces[$id]->getMeta();

        return $this->spaces[$id];
    }

    public function dropSpace(string $space): self
    {
        if (!$this->hasSpace($space)) {
            throw new Exception("No space $space");
        }

        foreach ($this->meta as $id => $row) {
            if ($row['name'] == $space) {
                unset($this->meta[$id]);
                if (array_key_exists($id, $this->spaces)) {
                    unset($this->spaces[$id]);
                }
                $this->mapper->getClient()->call("box.space.$space:drop");
                return $this;
            }
        }

        return $this;
    }

    public function getMeta(): array
    {
        $meta = [];

        foreach ($this->meta as $id => $row) {
            $meta[$id] = $this->getSpace($row['name'])->getMeta();
        }

        return $meta;
    }

    public function getSpace(int|string $id): Space
    {
        if (is_string($id)) {
            foreach ($this->meta as $row) {
                if ($row['name'] == $id) {
                    return $this->getSpace($row['id']);
                }
            }
            throw new Exception("Space $id not found");
        }

        if (!$id) {
            throw new Exception("Undefined space");
        }

        if (!array_key_exists($id, $this->spaces)) {
            $this->spaces[$id] = new Space($this->mapper, $this->meta[$id]);
        }

        return $this->spaces[$id];
    }

    public function getSpaces(): array
    {
        foreach ($this->meta as $id => $row) {
            if (!array_key_exists($id, $this->spaces)) {
                $this->getSpace($id);
            }
        }

        return array_values($this->spaces);
    }

    public function hasSpace(string $space): bool
    {
        foreach ($this->meta as $row) {
            if ($row['name'] == $space) {
                return true;
            }
        }

        return false;
    }

    public function once(string $name, Closure $callback)
    {
        $key = 'mapper-once' . $name;
        $row = $this->mapper->findOne('_schema', ['key' => $key]);
        if (!$row) {
            $this->mapper->create('_schema', ['key' => $key]);
            return $callback($this->mapper);
        }
    }

    public function reset(): self
    {
        $this->meta = [];

        $data = $this->mapper->getClient()->getSpace('_vspace')
            ->select(Criteria::allIterator());

        foreach ($data as $tuple) {
            $row = array_combine(['id', 'owner', 'name', 'engine', 'field_count', 'flags', 'format'], $tuple);
            $this->meta[$row['id']] = $row;
        }

        return $this;
    }

    public function revert(string $name): bool
    {
        $key = 'mapper-once' . $name;
        $row = $this->mapper->findOne('_schema', ['key' => $key]);
        if ($row) {
            $this->mapper->remove($row);
            return true;
        }

        return false;
    }

    public function setMeta(array $meta): self
    {
        $this->meta = $meta;
        return $this;
    }
}
