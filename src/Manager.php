<?php

namespace Tarantool\Mapper;

use Tarantool\Client;
use Tarantool\Mapper\Schema\Schema;
use Tarantool\Mapper\Schema\Meta;
use LogicException;

class Manager implements Contracts\Manager
{
    protected $meta;
    protected $schema;
    protected $client;
    protected $repositores = [];

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @return Contracts\Repository|Contracts\Entity
     */
    public function get($type, $id = null)
    {
        if (!array_key_exists($type, $this->repositores)) {
            $this->repositores[$type] = new Repository($this->getMeta()->get($type));
        }

        if ($id) {
            return $this->repositores[$type]->find($id);
        }

        return $this->repositores[$type];
    }

    /**
     * @return Contracts\Entity
     */
    public function save(Contracts\Entity $entity)
    {
        foreach ($this->repositores as $repository) {
            if ($repository->knows($entity)) {
                return $repository->save($entity);
            }
        }

        throw new LogicException('Entity should be related with repository');
    }

    /**
     * @return Contracts\Entity
     */
    public function make($type, $data)
    {
        return $this->save($this->get($type)->make($data));
    }

    /**
     * @return Client
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * @return Schema
     */
    public function getSchema()
    {
        if (!isset($this->schema)) {
            $this->schema = new Schema($this->getClient());
        }

        return $this->schema;
    }

    /**
     * @return Meta
     */
    public function getMeta()
    {
        if (!isset($this->meta)) {
            $this->meta = new Meta($this);
        }

        return $this->meta;
    }
}
