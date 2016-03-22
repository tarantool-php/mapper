<?php

namespace Tarantool\Mapper;

use Tarantool\Client;
use Tarantool\Mapper\Contracts;
use Tarantool\Mapper\Schema\Schema;
use Tarantool\Mapper\Schema\Metadata;
use LogicException;

class Manager implements Contracts\Manager
{
    protected $client;
    protected $repositores = [];

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @return Contracts\Repository
     */
    public function get($type)
    {
        if (!array_key_exists($type, $this->repositores)) {
            $this->repositores[$type] = new Repository($this->getMetadata()->get($type));
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

        throw new LogicException("Entity should be related with repository");
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
     * @return Metadata
     */
    public function getMetadata()
    {
        if (!isset($this->metadata)) {
            $this->metadata = new Metadata($this);
        }
        return $this->metadata;
    }
}
