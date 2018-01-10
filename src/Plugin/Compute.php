<?php

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

    public function afterCreate(Entity $entity, Space $space)
    {
        $name = $space->getName();

        if (array_key_exists($name, $this->dependency)) {
            foreach ($this->dependency[$name] as $info) {
                list($target, $callback) = $info;
                $this->initializePresenter($target, $callback, $entity);
            }
        }
    }

    public function afterRemove(Entity $entity, Space $space)
    {
        $name = $space->getName();

        if (array_key_exists($name, $this->dependency)) {
            foreach ($this->dependency[$name] as $info) {
                list($target, $callback) = $info;
                $this->getMapper()->remove($target, ['id' => $entity->id]);
            }
        }
    }

    public function afterUpdate(Entity $entity, Space $space)
    {
        $name = $space->getName();

        if (array_key_exists($name, $this->dependency)) {
            foreach ($this->dependency[$name] as $info) {
                list($target, $callback) = $info;
                $child = $this->getMapper()->findOne($target, $entity->id);
                foreach ($callback($entity) as $k => $v) {
                    $child->$k = $v;
                }
                $child->save();
            }
        }
    }

    public function beforeCreate(Entity $entity, Space $space)
    {
        if (in_array($entity, $this->entities)) {
            return true;
        }
        foreach ($this->dependency as $source => $dependencies) {
            foreach ($dependencies as $info) {
                list($target) = $info;
                if ($target == $space->getName()) {
                    throw new Exception("Space $target is computed from $source");
                }
            }
        }
    }

    public function register($source, $target, $callback)
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
    }

    protected function initializePresenter($target, $callback, Entity $source)
    {
        $entity = $this->getMapper()->getRepository($target)->create($source->id);
        foreach ($callback($source) as $k => $v) {
            $entity->$k = $v;
        }
        $this->entities[] = $entity;
        $entity->save();
    }

    protected function registerLuaTriggers($source, $destination, $body)
    {
        throw new Exception("Lua triggers not implemented");
    }
}
