<?php

namespace Tarantool\Mapper\Schema;

use Tarantool\Mapper\Contracts;
use LogicException;

class Meta implements Contracts\Meta
{
    protected $manager;
    protected $mapping = [];
    protected $types = [];

    public function __construct(Contracts\Manager $manager)
    {
        $this->manager = $manager;
        $this->mapping = [];
        $this->references = [];

        $client = $manager->getClient();
        foreach ($client->getSpace('mapping')->select([], 'space')->getData() as $mapping) {
            list($id, $spaceId, $line, $property, $type) = $mapping;
            if (!array_key_exists($spaceId, $this->mapping)) {
                $this->mapping[$spaceId] = [];
                $this->types[$spaceId] = [];
            }
            $this->mapping[$spaceId][$line] = $property;
            $this->types[$spaceId][$property] = $type;
        }
        foreach ($this->mapping as $spaceId => $collection) {
            ksort($collection);
            $this->mapping[$spaceId] = $collection;
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

            $this->types[$type] = new Type($this->manager, $type, $this->mapping[$spaceId], $this->types[$spaceId]);
        }

        return $this->types[$type];
    }

    /**
     * @return Type
     */
    public function make($type, array $fields = null)
    {
        if ($this->manager->getSchema()->hasSpace($type)) {
            throw new LogicException("Type $type exists");
        }

        $this->manager->getSchema()->makeSpace($type);

        $instance = new Type($this->manager, $type, [], []);

        $instance->addProperty('id');
        $instance->addIndex('id');

        if ($fields) {
            foreach ($fields as $field) {
                if ($field instanceof Contracts\Type) {
                    $instance->reference($field);
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
