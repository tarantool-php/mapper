<?php

namespace Tarantool\Mapper\Contracts;

use Tarantool\Client;

interface Manager
{
    /**
     * @return Repository
     */
    public function get($type);

    /**
     * @return Entity
     */
    public function save(Entity $entity);

    /**
     * @return Client
     */
    public function getClient();

    /**
     * @return Schema
     */
    public function getSchema();

    /**
     * @return Metadata
     */
    public function getMetadata();
}
