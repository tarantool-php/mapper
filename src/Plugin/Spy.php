<?php

declare(strict_types=1);

namespace Tarantool\Mapper\Plugin;

use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Plugin;
use Tarantool\Mapper\Space;

class Spy extends Plugin
{
    private $created = [];
    private $updated = [];
    private $removed = [];

    public function afterCreate(Entity $instance, Space $space): Entity
    {
        return $this->created[$this->getKey($instance, $space)] = $instance;
    }

    public function afterUpdate(Entity $instance, Space $space): Entity
    {
        $key = $this->getKey($instance, $space);
        if (!array_key_exists($key, $this->created)) {
            $this->updated[$key] = $instance;
        }

        return $instance;
    }

    public function beforeRemove(Entity $instance, Space $space): Entity
    {
        $key = $this->getKey($instance, $space);

        if (array_key_exists($key, $this->created)) {
            unset($this->created[$key]);
            return $instance;
        }

        if (array_key_exists($key, $this->updated)) {
            unset($this->updated[$key]);
        }

        return $this->removed[$key] = $instance;
    }

    public function reset(): self
    {
        $this->created = [];
        $this->updated = [];
        $this->removed = [];
        return $this;
    }

    private function getKey(Entity $instance, Space $space): string
    {
        $key = [$space->getName()];

        $format = $space->getFormat();
        foreach ($space->getPrimaryIndex()['parts'] as $part) {
            $field = array_key_exists(0, $part) ? $part[0] : $part['field'];
            $key[] = $instance->{$format[$field]['name']};
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

    public function hasChanges(): bool
    {
        return count($this->created) + count($this->updated) + count($this->removed) > 0;
    }
}
