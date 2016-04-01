<?php

namespace Tarantool\Mapper\Schema;

use Tarantool\Mapper\Contracts;
use LogicException;

class Type implements Contracts\Type
{
    protected $convention;
    protected $properties = [];
    protected $types = [];

    protected $manager;
    protected $spaceId;
    protected $name;

    public function __construct(Contracts\Manager $manager, $name, array $properties, array $types)
    {
        $this->manager = $manager;
        $this->name = $name;
        $this->convention = $manager->getMeta()->getConvention();
        $this->spaceId = $manager->getSchema()->getSpaceId($name);

        $this->properties = $properties;
        $this->types = $types;
    }

    public function getSpace()
    {
        return $this->getManager()->getClient()->getSpace($this->name);
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

    public function getMapping()
    {
        return $this->properties;
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

        sort($properties);
        $indexName = implode('_', $properties);

        if ($schema->hasIndex($this->getName(), $indexName)) {
            throw new LogicException("Index $indexName already exists!");
        }
        if (!$arguments) {
            $arguments = [];
        }

        if (!array_key_exists('parts', $arguments) || !count($arguments['parts'])) {
            $arguments['parts'] = [];
            foreach ($this->getMapping() as $index => $name) {
                if (in_array($name, $properties)) {
                    $arguments['parts'][] = $index + 1;
                    $arguments['parts'][] = $this->convention->getTarantoolType($this->types[$name]);
                }
            }
        }

        $schema->makeIndex($this->getName(), $indexName, $arguments);

        return $this;
    }

    /**
     * @param $property name
     *
     * @return Type
     */
    public function addProperty($first)
    {
        $properties = is_array($first) ? $first : func_get_args();

        foreach ($properties as $property) {
            if ($this->hasProperty($property)) {
                throw new LogicException("Duplicate property $property");
            }
            $this->types[$property] = $this->manager->getMeta()->getConvention()->getType($property);
            $this->manager->make('mapping', [
                'space' => $this->spaceId,
                'line' => count($this->properties),
                'property' => $property,
                'type' => $this->types[$property],
            ]);

            $this->properties[] = $property;
        }

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
        $this->types[$property] = $type;

        // update entity
        $row = $this->getManager()->get('mapping')->findOne([
            'space' => $this->spaceId,
            'line' => array_search($property, $this->properties),
        ]);
        $row->type = $type;
        $this->getManager()->save($row);

        return $this;
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
            $this->requiredProperties = ['id'];
            foreach ($this->getReferences() as $property => $reference) {
                $this->requiredProperties[] = $property;
            }
            foreach ($this->manager->getSchema()->listIndexes($this->getName()) as $name => $fields) {
                foreach ($fields as $num) {
                    $property = $this->properties[$num];
                    if (!in_array($property, $this->requiredProperties)) {
                        $this->requiredProperties[] = $property;
                    }
                }
            }
        }

        return $this->requiredProperties;
    }

    public function encode($input)
    {
        $output = [];
        foreach ($this->getMapping() as $index => $name) {
            if (array_key_exists($name, $input)) {
                $value = $input[$name];
                if ($this->isReference($name)) {
                    $value = $value->getId();
                } elseif ($this->convention->isPrimitive($this->types[$name])) {
                    $value = $this->convention->encode($this->types[$name], $value);
                }
                $output[$index] = $value;
            }
        }

        return $output;
    }

    public function decode($input)
    {
        $output = [];
        foreach ($this->getMapping() as $index => $name) {
            if (array_key_exists($index, $input)) {
                $output[$name] = $input[$index];
                if ($this->convention->isPrimitive($this->types[$name])) {
                    $output[$name] = $this->convention->decode($this->types[$name], $output[$name]);
                }
            }
        }

        return $output;
    }
}
