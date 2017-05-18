<?php

namespace Tarantool\Mapper\Plugins;

use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Plugin;
use Tarantool\Mapper\Space;

class Spy extends Plugin
{
    private $created = [];
    private $updated = [];
    private $removed = [];

    public function beforeCreate(Entity $instance, Space $space)
    {
        $this->created[$this->getKey($instance, $space)] = $instance;
    }

    public function beforeUpdate(Entity $instance, Space $space)
    {
        $key = $this->getKey($instance, $space);
        if (!array_key_exists($key, $this->created)) {
            $this->updated[$key] = $instance;
        }
    }

    public function beforeRemove(Entity $instance, Space $space)
    {
        $key = $this->getKey($instance, $space);

        if (array_key_exists($key, $this->created)) {
            unset($this->created[$key]);
            return;
        }

        if (array_key_exists($key, $this->updated)) {
            unset($this->updated[$key]);
        }

        $this->removed[$key] = $instance;
    }

    public function reset()
    {
        $this->created = [];
        $this->updated = [];
        $this->removed = [];
    }

    private function getKey(Entity $instance, Space $space)
    {
        $key = [$space->getName()];

        $format = $space->getFormat();
        foreach ($space->getPrimaryIndex()->parts as $part) {
            $key[] = $instance->{$format[$part[0]]['name']};
        }

        return implode(':', $key);
    }

    public function getChanges()
    {
        $result = (object) [];

        foreach (['created', 'updated', 'removed'] as $action) {
            $data = [];
            foreach ($this->$action as $key => $row) {
                list($space) = explode(':', $key);
                if (!array_key_exists($space, $data)) {
                    $data[$space] = [];
                }
                $data[$space][] = $row;
            }
            $result->$action = $data;
        }

        return $result;
    }

    public function hasChanges()
    {
        return count($this->created) + count($this->updated) + count($this->removed) > 0;
    }
}
