<?php

namespace Tarantool\Mapper\Schema;

use Tarantool\Mapper\Contracts;
use LogicException;

class Type implements Contracts\Type
{
    protected $properties = [];
    protected $manager;
    protected $name;

    public function __construct(Contracts\Manager $manager, $name, array $properties = null)
    {
        $this->manager = $manager;
        $this->name = $name;

        if($name == 'mapping') {
            $properties = ['id', 'space', 'line', 'property'];;
        }

        if($properties) {
            $this->properties = $properties;
        }
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
            if (!in_array($property, $this->properties)) {
                throw new LogicException("Unknown property $property for " . $this->name);
            }
        }

        $schema = $this->manager->getSchema();

        sort($properties);
        $indexName = implode('_', $properties);

        if($schema->hasIndex($this->getName(), $indexName)) {
            throw new LogicException("Index $indexName already exists!");
        }

        if(!count($arguments['parts'])) {
            $arguments['parts'] = [];
            foreach($this->getMapping() as $index => $name) {
                if(in_array($name, $properties)) {
                    $arguments['parts'][] = $index + 1;
                    $arguments['parts'][] = $name == 'id' ? 'NUM' : 'STR';
                }
            }
        }

        $schema->createIndex($this->getName(), $indexName, $arguments);
    }

    /**
     * @param $property name
     * @return Type
     */
    public function addProperty($first)
    {
        foreach (func_get_args() as $property) {
            if(in_array($property, $this->properties)) {
                throw new LogicException("Duplicate property $property");
            }
            $this->properties[] = $property;
        }
        return $this;
    }

    public function encode($input)
    {
        $output = [];
        foreach($this->getMapping() as $index => $name) {
            if(array_key_exists($name, $input)) {
                $output[$index] = $input[$name];
            }
        }
        return $output;
    }

    public function decode($input)
    {
        $output = [];
        foreach($this->getMapping() as $index => $name) {
            if(array_key_exists($index, $input)) {
                $output[$name] = $input[$index];
            }
        }
        return $output;

    }
}
