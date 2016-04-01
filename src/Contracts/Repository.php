<?php

namespace Tarantool\Mapper\Contracts;

interface Repository
{
    /**
     * @return Entity
     */
    public function create($data);

    /**
     * @return Entity|Entity[]
     */
    public function find($params, $first = false);

    /**
     * @return Entity
     */
    public function findOne($params);

    /**
     * @return Entity
     */
    public function save(Entity $entity);

    /**
     * @return Type
     */
    public function getType();
}
