<?php

namespace Tarantool\Mapper;

abstract class Plugin
{
    protected $mapper;

    public function __construct(Mapper $mapper)
    {
        $this->mapper = $mapper;
    }

    public function getRepositoryClass(Space $space) {}
    public function getEntityClass(Space $space) {}
    public function beforeCreate(Entity $instance, Space $space) {}
    public function beforeUpdate(Entity $instance, Space $space) {}
    public function beforeRemove(Entity $instance, Space $space) {}
}
