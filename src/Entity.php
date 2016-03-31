<?php

namespace Tarantool\Mapper;

use Closure;
use LogicException;

class Entity implements Contracts\Entity
{
    protected $original = [];
    protected $data = [];

    public function __construct(array $data = null)
    {
        $this->update($data);
    }

    public function update($data)
    {
        $this->original = $data;
        $this->data = $data;
    }

    public function __set($key, $value)
    {
        if ($key == 'id' && $this->getId()) {
            throw new LogicException('Id property is readonly');
        }

        $this->data[$key] = $value;
    }

    public function __get($key)
    {
        if (array_key_exists($key, $this->data)) {
            if ($this->data[$key] instanceof Closure) {
                $this->data[$key] = $this->data[$key]();
            }

            return $this->data[$key];
        }
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->__get('id');
    }

    /**
     * @return Entity
     */
    public function setId($id)
    {
        $this->__set('id', $id);

        return $this;
    }

    /**
     * @return array
     */
    public function toArray($recursive = false)
    {
        $array = [];
        foreach (array_keys($this->data) as $key) {
            $array[$key] = $this->__get($key);
            if ($array[$key] instanceof Contracts\Entity) {
                if ($recursive) {
                    $array[$key] = $array[$key]->toArray(true);
                }
            }
        }

        return $array;
    }

    public function getData()
    {
        $data = [];
        foreach (array_keys($this->data) as $key) {
            $data[$key] = $this->__get($key);
            if ($data[$key] instanceof Contracts\Entity) {
                $data[$key] = $data[$key]->id;
            }
        }

        return $data;
    }

    /**
     * @return array
     */
    public function pullChanges()
    {
        $changes = [];

        foreach ($this->data as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] != $value) {
                $changes[$key] = $value;
            }
        }

        $this->original = $this->data;

        return $changes;
    }
}
