<?php

namespace Tarantool\Mapper\Plugin;

use Exception;
use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Plugin;
use Tarantool\Mapper\Repository;
use Tarantool\Mapper\Space;

class UserClasses extends Plugin
{
    protected $repositoryMapping = [];
    protected $entityMapping = [];

    public function getRepositoryClass(Space $space)
    {
        if (array_key_exists($space->getName(), $this->repositoryMapping)) {
            return $this->repositoryMapping[$space->getName()];
        }
    }

    public function getEntityClass(Space $space, array $data)
    {
        if (array_key_exists($space->getName(), $this->entityMapping)) {
            return $this->entityMapping[$space->getName()];
        }
    }

    public function mapEntity($space, $class)
    {
        $this->validateSpace($space);

        if (!class_exists($class)) {
            throw new Exception("No class $class");
        }

        if (!is_subclass_of($class, Entity::class)) {
            throw new Exception("Entity should extend " . Entity::class . " class");
        }

        $this->entityMapping[$space] = $class;
    }

    public function mapRepository($space, $class)
    {
        $this->validateSpace($space);

        if (!class_exists($class)) {
            throw new Exception("No class $class");
        }

        if (!is_subclass_of($class, Repository::class)) {
            throw new Exception("Repository should extend " . Repository::class . " class");
        }

        $this->repositoryMapping[$space] = $class;
    }

    public function getRepositoryMapping()
    {
        return $this->repositoryMapping;
    }

    public function getEntityMapping()
    {
        return $this->entityMapping;
    }

    public function validateSpace($space)
    {
        if (!$this->mapper->getSchema()->hasSpace($space)) {
            throw new Exception("No space $space");
        }
    }
}
