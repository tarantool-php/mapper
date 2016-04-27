<?php

namespace Tarantool\Mapper\Schema;

use Tarantool\Mapper\Contracts;
use LogicException;

class Type implements Contracts\Type
{
    private $convention;
    private $properties = [];
    private $indexes = [];
    private $types = [];

    private $manager;
    private $space;
    private $spaceId;
    private $name;

    public function __construct(Contracts\Manager $manager, $name, array $properties, array $types, array $indexes)
    {
        $this->manager = $manager;
        $this->name = $name;
        $this->convention = $manager->getMeta()->getConvention();
        $this->spaceId = $manager->getSchema()->getSpaceId($name);

        $this->properties = $properties;
        $this->indexes = $indexes;
        $this->types = $types;
    }

    public function getSpace()
    {
        if (!$this->space) {
            $this->space = $this->getManager()->getClient()->getSpace($this->spaceId);
        }

        return $this->space;
    }

    public function getSpaceId()
    {
        return $this->spaceId;
    }

    public function getManager()
    {
        return $this->manager;
    }

    public function getName()
    {
        return $this->name;
    }

    public function addIndex($properties, array $arguments = null)
    {
        $properties = (array) $properties;
        foreach ($properties as $property) {
            if (!$this->hasProperty($property)) {
                throw new LogicException("Unknown property $property for ".$this->name);
            }
        }

        $schema = $this->manager->getSchema();

        $indexName = implode('_', $properties);
        if(strlen($indexName) > 32) {
            $indexName = md5($indexName);
        }

        if ($schema->hasIndex($this->getName(), $indexName)) {
            throw new LogicException("Index $indexName already exists!");
        }

        if (!$arguments) {
            $arguments = [];
        }

        if (!array_key_exists('parts', $arguments) || !count($arguments['parts'])) {
            $arguments['parts'] = [];
            foreach ($properties as $property) {
                $arguments['parts'][] = array_search($property, $this->properties) + 1;
                $arguments['parts'][] = $this->convention->getTarantoolType($this->types[$property]);
            }
        }

        $num = $schema->createIndex($this->getName(), $indexName, $arguments);
        $this->indexes[$num] = $properties;

        return $this;
    }

    /**
     * @param $property name
     *
     * @return Type
     */
    public function addProperty($name, $type = null)
    {
        if ($this->hasProperty($name)) {
            throw new LogicException("Duplicate property $name");
        }
        if (!$type) {
            $type = $this->manager->getMeta()->getConvention()->getType($name);
        }
        $this->types[$name] = $type;
        $property = $this->manager->create('property', [
            'space' => $this->spaceId,
            'index' => count($this->properties),
            'name' => $name,
            'type' => $this->types[$name],
        ]);

        $this->properties[$property->index] = $name;

        return $this;
    }

    public function hasProperty($name)
    {
        return in_array($name, $this->properties);
    }

    public function getProperties()
    {
        return $this->properties;
    }

    public function getPropertyType($property)
    {
        return $this->types[$property];
    }

    public function setPropertyType($property, $type)
    {
        if (is_array($property)) {
            foreach ($property as $prop) {
                $this->setPropertyType($prop, $type);
            }

            return $this;
        }

        $this->types[$property] = $type;

        // update entity
        $row = $this->getManager()->get('property')->findOne([
            'space' => $this->spaceId,
            'index' => array_search($property, $this->properties),
        ]);
        $row->type = $type;
        $this->getManager()->save($row);

        return $this;
    }

    public function removeProperty($name)
    {
        if (!$this->hasProperty($name)) {
            throw new LogicException("Unknown property $name");
        }

        foreach ($this->indexes as $index => $fields) {
            if ($fields != [$name] && in_array($name, $fields)) {
                throw new LogicException("Property is used by composite index $index");
            }
        }

        foreach ($this->indexes as $index => $fields) {
            if ($fields == [$name]) {
                unset($this->indexes[$index]);
            }
        }

        $index = array_search($name, $this->properties);

        unset($this->properties[$index]);
        unset($this->types[$name]);

        $property = $this->manager->get('property')->findOne([
            'space' => $this->spaceId,
            'index' => $index,
        ]);

        $this->manager->remove($property);
    }

    public function reference(Contracts\Type $foreign, $property = null)
    {
        if (!$property) {
            $property = $foreign->getName();
        }

        $this->addProperty($property);
        $this->setPropertyType($property, $foreign->getName());
        $this->addIndex($property, ['unique' => false]);

        return $this;
    }

    public function isReference($property)
    {
        return !$this->convention->isPrimitive($this->types[$property]);
    }

    public function getReferenceProperty(Contracts\Type $type)
    {
        $properties = [];
        foreach ($this->types as $property => $propertyType) {
            if ($type->getName() == $propertyType) {
                $properties[] = $property;
            }
        }
        if (!count($properties)) {
            throw new LogicException('Type '.$this->getName().' is not related with '.$type->getName());
        }
        if (count($properties) > 1) {
            throw new LogicException('Multiple type reference found');
        }

        return $properties[0];
    }

    public function getReferences()
    {
        $references = [];
        $convention = $this->manager->getMeta()->getConvention();
        foreach ($this->types as $property => $type) {
            if (!$convention->isPrimitive($type)) {
                $references[$property] = $type;
            }
        }

        return $references;
    }

    public function getRequiredProperties()
    {
        if (!isset($this->requiredProperties)) {
            $this->requiredProperties = ['id' => 1];
            $indexList = $this->manager->getSchema()->listIndexes($this->getName());
            foreach ($indexList as $name => $fields) {
                foreach ($fields as $num) {
                    $this->requiredProperties[$this->properties[$num]] = true;
                }
            }
            $this->requiredProperties = array_keys($this->requiredProperties);
        }

        return $this->requiredProperties;
    }

    public function getIndex($num)
    {
        return $this->indexes[$num];
    }

    public function dropIndex($num)
    {
        if (is_array($num)) {
            $num = $this->findIndex($num);
        }
        $this->getManager()->getSchema()->dropIndex($this->spaceId, $num);
        unset($this->indexes[$num]);
    }

    public function getIndexes()
    {
        return $this->indexes;
    }

    public function findIndex($query)
    {
        if (!count($query)) {
            return 0;
        }

        sort($query);
        foreach ($this->indexes as $name => $fields) {
            if ($fields == $query) {
                return $name;
            }
        }

        // cast partial index
        $casting = [];

        foreach ($this->indexes as $name => $fields) {
            if (!count(array_diff($query, $fields))) {
                $casting[count(array_diff($fields, $query))] = $name;
            }
        }
        ksort($casting);

        return array_shift($casting);
    }

    public function getIndexTuple($index, $params)
    {
        $tuple = [];
        foreach ($this->indexes[$index] as $property) {
            if (array_key_exists($property, $params)) {
                $tuple[array_search($property, $this->indexes[$index])] = $params[$property];
            }
        }

        return $tuple;
    }

    public function getCompleteTuple($input)
    {
        $tuple = $this->getTuple($input);
        $required = $this->getRequiredProperties();

        foreach ($this->getProperties() as $index => $field) {
            if (in_array($field, $required) && !array_key_exists($index, $tuple)) {
                if ($this->isReference($field)) {
                    $tuple[$index] = 0;
                } else {
                    $tuple[$index] = '';
                }
            }
        }

        // normalize tuple
        if (array_values($tuple) != $tuple) {
            // index was skipped
            $max = max(array_keys($tuple));
            foreach (range(0, $max) as $index) {
                if (!array_key_exists($index, $tuple)) {
                    $tuple[$index] = null;
                }
            }
            ksort($tuple);
        }

        return $tuple;
    }

    public function getTuple($input)
    {
        $output = [];
        foreach ($this->getProperties() as $index => $name) {
            if (array_key_exists($name, $input)) {
                $output[$index] = $this->encodeProperty($name, $input[$name]);
            }
        }

        return $output;
    }

    public function fromTuple($input)
    {
        $output = [];
        foreach ($this->getProperties() as $index => $name) {
            if (array_key_exists($index, $input)) {
                $output[$name] = $this->decodeProperty($name, $input[$index]);
            }
        }

        return $output;
    }

    public function encodeProperty($name, $value)
    {
        return $this->convention->encode($this->types[$name], $value);
    }

    public function decodeProperty($name, $value)
    {
        return $this->convention->decode($this->types[$name], $value);
    }
}
