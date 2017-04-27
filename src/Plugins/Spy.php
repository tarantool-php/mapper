<?php

namespace Tarantool\Mapper\Plugins;

use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Plugin;
use Tarantool\Mapper\Space;

class Spy extends Plugin
{
    private $create = [];
    private $update = [];
    private $remove = [];

    public function beforeCreate(Entity $instance, Space $space)
    {
        $this->create[$this->getKey($instance, $space)] = $instance;
    }

    public function beforeUpdate(Entity $instance, Space $space)
    {
        $key = $this->getKey($instance, $space);
        if(!array_key_exists($key, $this->create)) {
            $this->update[$key] = $instance;
        }
    }

    public function beforeRemove(Entity $instance, Space $space)
    {
        $key = $this->getKey($instance, $space);

        if(array_key_exists($key, $this->create)) {
            unset($this->create[$key]);
            return;
        }

        if(array_key_exists($key, $this->update)) {
            unset($this->update[$key]);
        }

        $this->remove[$key] = $instance;
    }

    public function reset()
    {
        $this->create = [];
        $this->update = [];
        $this->remove = [];
    }

    private function getKey(Entity $instance, Space $space)
    {
        $key = [$space->getName()];

        $format = $space->getFormat();
        foreach($space->getPrimaryIndex()->parts as $part) {
            $key[] = $instance->{$format[$part[0]]['name']};
        }

        return implode(':', $key);
    }

    public function getChanges()
    {
        $result = (object) [];

        foreach(['create', 'update', 'remove'] as $action) {
            $data = [];
            foreach($this->$action as $key => $row) {
                list($space) = explode(':', $key);
                if(!array_key_exists($space, $data)) {
                    $data[$space][] = $row;
                }
            }
            $result->$action = $data;
        }

        return $result;
    }

    public function hasChanges()
    {
        return count($this->create) + count($this->update) + count($this->remove) > 0;
    }
}
