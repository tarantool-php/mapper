<?php

namespace Tarantool\Mapper;

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
            if (is_callable($this->data[$key])) {
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
    public function toArray()
    {
        return $this->data;
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
