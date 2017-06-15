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
        return $this->getRepository()->save($this);
    }

    public function __debugInfo()
    {
        $info = get_object_vars($this);

        unset($info['_repository']);

        if (array_key_exists('app', $info) && is_object($info['app'])) {
            unset($info['app']);
        }

        return $info;
    }
}
