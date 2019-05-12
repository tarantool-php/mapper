<?php

declare(strict_types=1);

namespace Tarantool\Mapper;

abstract class Plugin
{
    protected $mapper;

    public function __construct(Mapper $mapper)
    {
        $this->mapper = $mapper;
    }

    public function getMapper()
    {
        return $this->mapper;
    }

    public function afterInstantiate(Entity $instance) : Entity
    {
        return $instance;
    }

    public function getRepositoryClass(Space $space) : ?string
    {
        return null;
    }

    public function getEntityClass(Space $space, array $data) : ?string
    {
        return null;
    }

    public function generateKey(Entity $instance, Space $space) : Entity
    {
        return $instance;
    }

    public function beforeCreate(Entity $instance, Space $space) : Entity
    {
        return $instance;
    }

    public function afterCreate(Entity $instance, Space $space) : Entity
    {
        return $instance;
    }

    public function beforeUpdate(Entity $instance, Space $space) : Entity
    {
        return $instance;
    }

    public function afterUpdate(Entity $instance, Space $space) : Entity
    {
        return $instance;
    }

    public function beforeRemove(Entity $instance, Space $space) : Entity
    {
        return $instance;
    }

    public function afterRemove(Entity $instance, Space $space) : Entity
    {
        return $instance;
    }
}
