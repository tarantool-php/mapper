<?php

declare(strict_types=1);

namespace Tarantool\Mapper\Plugin;

use Closure;
use Exception;
use Tarantool\Mapper\Plugin;
use Tarantool\Mapper\Space;
use Tarantool\Mapper\Entity;

class Compute extends Plugin
{
    protected $dependency = [];
    protected $entities = [];

    public function afterCreate(Entity $entity, Space $space): Entity
    {
        if (array_key_exists($space->name, $this->dependency)) {
            foreach ($this->dependency[$space->name] as [$target, $callback]) {
                $this->initializePresenter($target, $callback, $entity);
            }
        }

        return $entity;
    }

    public function afterRemove(Entity $entity, Space $space): Entity
    {
        if (array_key_exists($space->name, $this->dependency)) {
            foreach ($this->dependency[$space->name] as [$target, $callback]) {
                $this->getMapper()->remove($target, ['id' => $entity->id]);
            }
        }

        return $entity;
    }

    public function afterUpdate(Entity $entity, Space $space): Entity
    {
        if (array_key_exists($space->name, $this->dependency)) {
            foreach ($this->dependency[$space->name] as [$target, $callback]) {
                $child = $this->getMapper()->findOne($target, $entity->id);
                foreach ($callback($entity) as $k => $v) {
                    $child->$k = $v;
                }
                $child->save();
            }
        }

        return $entity;
    }

    public function beforeCreate(Entity $entity, Space $space): Entity
    {
        if (in_array($entity, $this->entities)) {
            return $entity;
        }
        foreach ($this->dependency as $source => $dependencies) {
            foreach ($dependencies as [$target]) {
                if ($target == $space->name) {
                    throw new Exception("Space $target is computed from $source");
                }
            }
        }

        return $entity;
    }

    public function register(string $source, string $target, Closure $callback): self
    {
        if ($callback instanceof Closure) {
            if (!array_key_exists($source, $this->dependency)) {
                $this->dependency[$source] = [];
            }
            $this->dependency[$source][] = [$target, $callback];
            foreach ($this->getMapper()->find($source) as $entity) {
                $this->initializePresenter($target, $callback, $entity);
            }
        } else {
            $this->registerLuaTriggers($source, $target, $callback);
        }

        return $this;
    }

    protected function initializePresenter($target, $callback, Entity $source): self
    {
        $entity = $this->getMapper()->getRepository($target)->create($source->id);
        foreach ($callback($source) as $k => $v) {
            $entity->$k = $v;
        }
        $this->entities[] = $entity;
        $entity->save();

        return $this;
    }

    protected function registerLuaTriggers(string $source, string $destination, string $body)
    {
        throw new Exception("Lua triggers not implemented");
    }
}
