<?php

declare(strict_types=1);

namespace Tarantool\Mapper;

use BadMethodCallException;
use Exception;
use Tarantool\Mapper\Plugin\Annotation;

class Entity
{
    private $gettersCache = [];
    private $originalTuple;
    private $repositoryInstance;

    public function __call($name, $arguments)
    {
        if (!array_key_exists($name, $this->gettersCache)) {
            $this->gettersCache[$name] = 'exception';
            if (strpos($name, 'get') === 0) {
                $property = lcfirst(substr($name, 3));
                $mapper = $this->getRepository()->getMapper();
                if (property_exists($this, $property)) {
                    $reference = $this->getRepository()->getSpace()->getProperty($property)->reference;
                    if ($reference) {
                        $this->gettersCache[$name] = ['findOrFail', $reference, 'id', $property];
                    }
                } elseif (strpos($property, 'Collection') !== false) {
                    $property = substr($property, 0, -10);
                    $targetSpace = Converter::toUnderscore($property);
                    if ($mapper->getSchema()->hasSpace($targetSpace)) {
                        $localSpace = $this->getRepository()->getSpace()->name;
                        $candidates = [];
                        foreach ($mapper->getSchema()->getSpace($targetSpace)->getProperties() as $property) {
                            if ($property->reference == $localSpace) {
                                $candidates[] = $property->name;
                            }
                        }
                        if (count($candidates) > 1) {
                            throw new Exception("Multiple references from $targetSpace to $localSpace");
                        }
                        if (count($candidates) == 1) {
                            $this->gettersCache[$name] = ['find', $targetSpace, $candidates[0], 'id'];
                        }
                    }
                }
            }
        }

        if ($this->gettersCache[$name] == 'exception') {
            throw new BadMethodCallException("Call to undefined method " . get_class($this) . '::' . $name);
        }

        [$method, $space, $key, $field] = $this->gettersCache[$name];

        return $this->getRepository()
            ->getMapper()
            ->$method($space, [
                $key => $this->$field,
            ]);
    }

    public function __construct(Repository $repository, $tuple = [])
    {
        $this->repositoryInstance = $repository;
        $this->setOriginalTuple($tuple);
    }

    public function __debugInfo()
    {
        $info = get_object_vars($this);

        unset($info['repositoryInstance']);
        unset($info['originalTuple']);
        unset($info['gettersCache']);

        if (array_key_exists('app', $info) && is_object($info['app'])) {
            unset($info['app']);
        }

        return $info;
    }

    public function getOriginalTuple(): array
    {
        return $this->originalTuple;
    }

    public function getRepository(): Repository
    {
        return $this->repositoryInstance;
    }

    public function getTupleChanges()
    {
        $changes = [];
        foreach ($this->toTuple() as $k => $v) {
            if (!array_key_exists($k, $this->originalTuple) || $this->originalTuple[$k] !== $v) {
                $changes[$k] = $v;
            }
        }

        return $changes;
    }

    public function save(): Entity
    {
        return $this->getRepository()->save($this);
    }

    public function setOriginalTuple(array $tuple): self
    {
        foreach ($this->getRepository()->getSpace()->getMap($tuple) as $k => $v) {
            $this->$k = $v;
        }

        $this->originalTuple = $tuple;
        return $this;
    }

    public function toArray(): array
    {
        $data = [];
        foreach ($this->getRepository()->getSpace()->getFields() as $field) {
            $data[$field] = $this->$field;
        }

        return $data;
    }

    public function toTuple(): array
    {
        $tuple = [];
        $space = $this->getRepository()->getSpace();
        $schema = $space->getMapper()->getSchema();

        foreach ($space->getFields() as $index => $field) {
            $value = $this->$field;
            $property = $space->getProperty($field);
            if ($value === null) {
                if ($property->defaultValue !== null) {
                    $value = $property->defaultValue;
                } elseif (!$property->isNullable && $field !== $space->getIndex(0)->getProperty()?->name) {
                    $value = Converter::getDefaultValue($property->type);
                }
            }

            $tuple[$index] = Converter::formatValue($property->type, $value);
        }

        return $tuple;
    }
}
