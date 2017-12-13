<?php

namespace Tarantool\Mapper;

use BadMethodCallException;
use Exception;
use Tarantool\Mapper\Plugin\Annotation;

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
            $property = lcfirst(substr($name, 3));
            $mapper = $this->getRepository()->getMapper();
            if (property_exists($this, $property)) {
                $reference = $this->getRepository()->getSpace()->getReference($property);
                if ($reference) {
                    return $mapper->findOrFail($reference, [
                        'id' => $this->$property,
                    ]);
                }
            } else if(strpos($property, 'Collection') !== false) {
                $property = substr($property, 0, -10);
                $targetSpace = $mapper->getSchema()->toUnderscore($property);
                if ($mapper->getSchema()->hasSpace($targetSpace)) {
                    $localSpace = $this->getRepository()->getSpace()->getName();
                    $candidates = [];
                    foreach ($mapper->getSchema()->getSpace($targetSpace)->getFormat() as $row) {
                        if (array_key_exists('reference', $row) && $row['reference'] == $localSpace) {
                            $candidates[] = $row['name'];
                        }
                    }
                    if (count($candidates) == 1) {
                        return $mapper->find($targetSpace, [
                            $candidates[0] => $this->id
                        ]);
                    }
                    if (count($candidates) > 1) {
                        throw new Exception("Multiple references from $targetSpace to $localSpace");
                    }
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
