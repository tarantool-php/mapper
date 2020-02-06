<?php

declare(strict_types=1);

namespace Tarantool\Mapper;

use Exception;
use Tarantool\Client\Client;

class Mapper
{
    private $client;
    private $plugins = [];
    private $schema;
    private $bootstrap;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function getPlugin($mixed)
    {
        if (!is_subclass_of($mixed, Plugin::class)) {
            throw new Exception("Plugin should extend ".Plugin::class." class");
        }

        $plugin = is_object($mixed) ? $mixed : new $mixed($this);
        $class = get_class($plugin);

        if ($plugin == $mixed && array_key_exists($class, $this->plugins)) {
            // overwrite plugin instance
            throw new Exception($class.' is registered');
        }

        if (!array_key_exists($class, $this->plugins)) {
            $this->plugins[$class] = $plugin;
        }

        return $this->plugins[$class];
    }

    public function create(string $space, $data) : Entity
    {
        return $this->getRepository($space)->create($data)->save();
    }

    public function findOne(string $space, $params = []) : ?Entity
    {
        return $this->getRepository($space)->findOne($params);
    }

    public function findOrCreate(string $space, $params = [], $data = []) : Entity
    {
        return $this->getRepository($space)->findOrCreate($params, $data)->save();
    }

    public function findOrFail(string $space, $params = []) : Entity
    {
        return $this->getRepository($space)->findOrFail($params);
    }

    public function find(string $space, $params = []) : array
    {
        return $this->getRepository($space)->find($params);
    }

    public function findRepository(Entity $instance) : Repository
    {
        foreach ($this->getSchema()->getSpaces() as $space) {
            if ($space->getRepository()->knows($instance)) {
                return $space->getRepository();
            }
        }

        throw new Exception("No Repository for given Entity");
    }

    public function getBootstrap() : Bootstrap
    {
        return $this->bootstrap ?: $this->bootstrap = new Bootstrap($this);
    }

    public function getClient() : Client
    {
        return $this->client;
    }

    public function getMeta() : array
    {
        return [
            'schema' => $this->getSchema()->getMeta(),
        ];
    }

    public function hasPlugin(string $class) : bool
    {
        return array_key_exists($class, $this->plugins);
    }

    public function getPlugins() : array
    {
        return array_values($this->plugins);
    }

    public function getRepository(string $space) : Repository
    {
        return $this->getSchema()->getSpace($space)->getRepository();
    }

    public function getRepositories() : array
    {
        $repositories = [];
        foreach ($this->getSchema()->getSpaces() as $space) {
            if ($space->repositoryExists()) {
                $repositories[] = $space->getRepository();
            }
        }
        return $repositories;
    }

    public function getSchema() : Schema
    {
        return $this->schema ?: $this->schema = new Schema($this);
    }

    public function remove($space, $params = []) : self
    {
        if ($space instanceof Entity) {
            $this->findRepository($space)->removeEntity($space);
        } else {
            $this->getRepository($space)->remove($params);
        }
        return $this;
    }

    public function save(Entity $instance) : Entity
    {
        return $this->findRepository($instance)->save($instance);
    }

    public function setMeta(array $meta) : self
    {
        if ($this->schema) {
            $this->schema->setMeta($meta['schema']);
        } else {
            $this->schema = new Schema($this, $meta['schema']);
        }
        return $this;
    }
}
