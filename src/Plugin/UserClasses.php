<?php

declare(strict_types=1);

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

    public function getRepositoryClass(Space $space): ?string
    {
        if (array_key_exists($space->name, $this->repositoryMapping)) {
            return $this->repositoryMapping[$space->name];
        }

        return null;
    }

    public function getEntityClass(Space $space, array $data): ?string
    {
        if (array_key_exists($space->name, $this->entityMapping)) {
            return $this->entityMapping[$space->name];
        }

        return null;
    }

    public function mapEntity($space, $class): self
    {
        $this->validateSpace($space);

        if (!class_exists($class)) {
            throw new Exception("No class $class");
        }

        if (!is_subclass_of($class, Entity::class)) {
            throw new Exception("Entity should extend " . Entity::class . " class");
        }

        $this->entityMapping[$space] = $class;
        return $this;
    }

    public function mapRepository(string $space, string $class): self
    {
        $this->validateSpace($space);

        if (!class_exists($class)) {
            throw new Exception("No class $class");
        }

        if (!is_subclass_of($class, Repository::class)) {
            throw new Exception("Repository should extend " . Repository::class . " class");
        }

        $this->repositoryMapping[$space] = $class;

        return $this;
    }

    public function getRepositoryMapping(): array
    {
        return $this->repositoryMapping;
    }

    public function getEntityMapping(): array
    {
        return $this->entityMapping;
    }

    public function validateSpace(string $space): bool
    {
        if (!$this->mapper->getSchema()->hasSpace($space)) {
            throw new Exception("No space $space");
        }

        return true;
    }
}
