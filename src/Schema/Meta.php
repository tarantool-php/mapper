<?php

namespace Tarantool\Mapper\Schema;

use Tarantool\Mapper\Contracts;
use LogicException;

class Meta implements Contracts\Meta
{
    protected $manager;
    protected $property = [];
    protected $indexes = [];
    protected $types = [];

    public function __construct(Contracts\Manager $manager)
    {
        $this->manager = $manager;

        $client = $manager->getClient();
        foreach ($client->getSpace('property')->select([], 'space')->getData() as $property) {
            list($id, $spaceId, $line, $name, $type) = $property;
            if (!array_key_exists($spaceId, $this->property)) {
                $this->property[$spaceId] = [];
                $this->types[$spaceId] = [];
            }
            $this->property[$spaceId][$line] = $name;
            $this->types[$spaceId][$name] = $type;
        }
        foreach ($client->getSpace('_vindex')->select([], 'primary')->getData() as $index) {
            list($spaceId, $num, $name, $type, $params, $properties) = $index;
            if (!array_key_exists($spaceId, $this->property)) {
                // tarantool space index
                continue;
            }
            if (!isset($this->indexes[$spaceId])) {
                $this->indexes[$spaceId] = [];
            }
            $this->indexes[$spaceId][$name] = [];
            foreach ($properties as $row) {
                list($part, $type) = $row;
                $this->indexes[$spaceId][$name][] = $this->property[$spaceId][$part];
            }
        }
        foreach ($this->property as $spaceId => $collection) {
            ksort($collection);
            $this->property[$spaceId] = $collection;
        }
    }

    /**
     * @return Type
     */
    public function get($type)
    {
        if (!array_key_exists($type, $this->types)) {
            $spaceId = $this->manager->getSchema()->getSpaceId($type);
            if (!$spaceId) {
                throw new LogicException("Type $type not exists");
            }

            $this->types[$type] = new Type(
                $this->manager, $type,
                $this->property[$spaceId],
                $this->types[$spaceId],
                $this->indexes[$spaceId]
            );
        }

        return $this->types[$type];
    }

    /**
     * @return Type
     */
    public function create($type, array $fields = null)
    {
        if ($this->manager->getSchema()->hasSpace($type)) {
            throw new LogicException("Type $type exists");
        }

        $this->manager->getSchema()->createSpace($type);

        $instance = new Type($this->manager, $type, [], [], []);

        $instance->addProperty('id');
        $instance->addIndex('id');

        if ($fields) {
            foreach ($fields as $index => $field) {
                if ($field instanceof Contracts\Type) {
                    if (!is_numeric($index)) {
                        $instance->reference($field, $index);
                    } else {
                        $instance->reference($field);
                    }
                } else {
                    $instance->addProperty($field);
                }
            }
        }

        return $this->types[$type] = $instance;
    }

    public function setConvention(Contracts\Convention $convention)
    {
        $this->convention = $convention;

        return $this;
    }

    public function getConvention()
    {
        if (!isset($this->convention)) {
            $this->convention = new Convention();
        }

        return $this->convention;
    }
}
