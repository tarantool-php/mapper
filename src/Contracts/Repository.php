<?php

namespace Tarantool\Mapper\Contracts;

interface Repository
{
    /**
     * @return Entity
     */
    public function make();

    /**
     * @return Entity[]
     */
    public function find($params, $first = false);

    /**
     * @return Entity
     */
    public function save(Entity $entity);
}
