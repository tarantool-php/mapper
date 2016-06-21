<?php

namespace Tarantool\Mapper\Entities;

use LogicException;

class Entity implements \Tarantool\Mapper\Contracts\Entity
{
    private $id;

    public function __construct(array $data = null)
    {
        if ($data) {
            $this->update($data);
        }
    }

    public function update($data)
    {
        foreach ($data as $k => $v) {
            $this->$k = $v;
        }
    }

    public function __set($key, $value)
    {
        if ($key == 'id' && $this->getId()) {
            throw new LogicException('Id property is readonly');
        }

        $this->$key = $value;
    }

    public function __get($key)
    {
        if (property_exists($this, $key)) {
            return $this->$key;
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
        foreach (get_object_vars($this) as $k => $v) {
            $array[$k] = $v;
            if ($v instanceof Contracts\Entity) {
                $array[$k] = $v->toArray($recursive);
            }
        }

        return $array;
    }

    public function __call($name, $params)
    {
        if(strlen($name) > 3) {
            $property = substr($name, 3);
            $property[0] = strtolower($property[0]);
            if(strpos($name, 'get') === 0) {
                return $this->__get($property);
            }
            if(strpos($name, 'set') === 0) {
                return $this->__set($property, $params[0]);
            }
        }
    }
}
