<?php

namespace Tarantool\Mapper\Plugins;

use Exception;
use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Plugin;
use Tarantool\Mapper\Repository;
use Tarantool\Mapper\Space;

class UserClasses extends Plugin
{
    private $repositories = [];
    private $entities = [];

    public function getRepositoryClass(Space $space)
    {
        if(array_key_exists($space->getName(), $this->repositories)) {
            return $this->repositories[$space->getName()];
        }
    }

    public function getEntityClass(Space $space)
    {
        if(array_key_exists($space->getName(), $this->entities)) {
            return $this->entities[$space->getName()];
        }
    }

    public function mapEntity($space, $class)
    {
        $this->validateSpace($space);

        if(!class_exists($class)) {
            throw new Exception("No class $class");
        }

        if(!is_subclass_of($class, Entity::class)) {
            throw new Exception("Entity should extend " . Entity::class . " class");
        }

        $this->entities[$space] = $class;
    }

    public function mapRepository($space, $class)
    {
        $this->validateSpace($space);

        if(!class_exists($class)) {
            throw new Exception("No class $class");
        }

        if(!is_subclass_of($class, Repository::class)) {
            throw new Exception("Repository should extend " . Repository::class . " class");
        }

        $this->repositories[$space] = $class;
    }

    public function validateSpace($space)
    {
        if(!$this->mapper->getSchema()->hasSpace($space)) {
            throw new Exception("No space $space");
        }
    }
}
