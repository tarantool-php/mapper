<?php

namespace Tarantool\Mapper;

use Tarantool\Client\Client;

class Mapper
{
    private $schema;
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function getSchema()
    {
        return $this->schema ?: $this->schema = new Schema($this);
    }

    public function getClient()
    {
        return $this->client;
    }

    public function getRepository($space)
    {
        return $this->getSchema()->getSpace($space)->getRepository();
    }

    public function findOne($space, $params = [])
    {
        return $this->getRepository($space)->findOne($params);
    }

    public function find($space, $params = [])
    {
        return $this->getRepository($space)->find($params);
    }

    public function create($space, $data)
    {
        $instance = $this->getRepository($space)->create($data);
        $this->getRepository($space)->save($instance);
        return $instance;
    }

    public function save($instance)
    {
        foreach($this->getSchema()->getSpaces() as $space) {
            if($space->getRepository()->knows($instance)) {
                $space->getRepository()->save($instance);
            }
        }
    }
}