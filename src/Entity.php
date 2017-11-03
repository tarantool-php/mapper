<?php

namespace Tarantool\Mapper;

use BadMethodCallException;

class Entity
{
    private $_repository;

    public function __construct(Repository $repository)
    {
        $this->_repository = $repository;
    }

    public function getRepository()
    {
        return $this->_repository;
    }

    public function save()
    {
        return $this->getRepository()->save($this);
    }

    public function __call($name, $arguments)
    {
       if (strpos($name, 'get') === 0) {
            $property = strtolower(substr($name, 3));
            if (property_exists($this, $property)) {
                $reference = $this->getRepository()->getSpace()->getReference($property);
                if ($reference) {
                    return $this->getRepository()->getMapper()
                        ->findOrFail($reference, [
                            'id' => $this->$property,
                        ]);
                }
            }
        }
        throw new BadMethodCallException("Call to undefined method ". get_class($this).'::'.$name);
    }

    public function __debugInfo()
    {
        $info = get_object_vars($this);

        unset($info['_repository']);

        if (array_key_exists('app', $info) && is_object($info['app'])) {
            unset($info['app']);
        }

        return $info;
    }
}
