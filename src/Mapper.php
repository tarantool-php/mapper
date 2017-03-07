<?php

namespace Tarantool\Mapper;

use Tarantool\Client\Client;
use Exception;

class Mapper
{
    private $client;
    private $plugins = [];
    private $schema;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function addPlugin($class)
    {
        if(!is_subclass_of($class, Plugin::class)) {
            throw new Exception("Plugin should extend " . Plugin::class . " class");
        }

        $plugin = new $class($this);
        $this->plugins[] = $plugin;

        return $plugin;
    }

    public function create($space, $data)
    {
        $instance = $this->getRepository($space)->create($data);
        $this->getRepository($space)->save($instance);
        return $instance;
    }

    public function findOne($space, $params = [])
    {
        return $this->getRepository($space)->findOne($params);
    }

    public function find($space, $params = [])
    {
        return $this->getRepository($space)->find($params);
    }

    public function findRepository(Entity $instance)
    {
        foreach($this->getSchema()->getSpaces() as $space) {
            if($space->getRepository()->knows($instance)) {
                return $space->getRepository();
            }
        }

        throw new Exception("No Repository for given Entity");
    }

    public function getClient()
    {
        return $this->client;
    }

    public function getPlugins()
    {
        return $this->plugins;
    }

    public function getRepository($space)
    {
        return $this->getSchema()->getSpace($space)->getRepository();
    }

    public function getSchema()
    {
        return $this->schema ?: $this->schema = new Schema($this);
    }

    public function save(Entity $instance)
    {
        $this->findRepository($instance)->save($instance);
    }
}