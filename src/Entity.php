<?php

namespace Tarantool\Mapper;

class Entity
{
    private $_repository;

    public function __construct(Repository $repository)
    {
        $this->_repository = $repository;
    }

    public function getRepository()
    {
        return $this->_repository;
    }

    public function save()
    {
        $this->getRepository()->save($this);
    }

    public function __debugInfo()
    {
        $info = get_object_vars($this);
        unset($info['_repository']);
        return $info;
    }
}
