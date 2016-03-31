<?php

namespace Tarantool\Mapper\Schema;

use Tarantool\Mapper\Contracts;
use LogicException;

class Type implements Contracts\Type
{
    protected $properties = [];
    protected $references = [];
    protected $manager;
    protected $name;

    public function __construct(Contracts\Manager $manager, $name,
                                array $properties = null, array $references = null)
    {
        $this->manager = $manager;
        $this->name = $name;

        if ($name == 'mapping') {
            $properties = ['id', 'space', 'line', 'property'];
        }

        if ($properties) {
            $this->properties = $properties;
        }

        if ($references) {
            foreach ($references as $reference) {
                $this->references[$reference->property] = $reference;
            }
        }
    }

    public function getSpace()
    {
        return $this->getManager()->getClient()->getSpace($this->name);
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
                    $arguments['parts'][] = $name == 'id' || $this->isReference($name) ? 'NUM' : 'STR';
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

            $this->manager->make('mapping', [
                'space' => $this->name,
                'line' => count($this->properties),
                'property' => $property,
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

    public function reference(Contracts\Type $foreign, $property = null)
    {
        if (!$property) {
            $property = $foreign->getName();
        }

        $this->addProperty($property);

        $this->references[$property] = $this->manager->make('reference', [
            'space' => $this->name,
            'property' => $property,
            'type' => $foreign->getName(),
        ]);

        $this->addIndex($property, ['unique' => false]);

        return $this;
    }

    public function isReference($name)
    {
        return array_key_exists($name, $this->references);
    }

    public function getReferenceProperty(Contracts\Type $type)
    {
        $properties = [];
        foreach ($this->references as $property => $reference) {
            if ($reference->type == $type->getName()) {
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
        foreach ($this->references as $property => $config) {
            $references[$property] = $config->type;
        }

        return $references;
    }

    public function encode($input)
    {
        $output = [];
        foreach ($this->getMapping() as $index => $name) {
            if (array_key_exists($name, $input)) {
                $value = $input[$name];
                if ($this->isReference($name)) {
                    $value = $value->getId();
                }
                $output[$index] = $value;
            }
        }

        return $output;
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

    public function decode($input)
    {
        $output = [];
        foreach ($this->getMapping() as $index => $name) {
            if (array_key_exists($index, $input)) {
                $output[$name] = $input[$index];
                if ($this->isReference($name)) {
                    $manager = $this->getManager();
                    $type = $this->references[$name]->type;
                    $id = $output[$name];
                    $output[$name] = function () use ($manager, $type, $id) {
                        return $manager->get($type)->find($id);
                    };
                }
            }
        }

        return $output;
    }
}
