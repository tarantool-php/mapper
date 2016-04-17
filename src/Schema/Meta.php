<?php

namespace Tarantool\Mapper\Schema;

use Tarantool\Mapper\Contracts;
use Exception;
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
            $this->indexes[$spaceId][$num] = [];
            foreach ($properties as $row) {
                list($part, $type) = $row;
                $this->indexes[$spaceId][$num][] = $this->property[$spaceId][$part];
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

    public function has($type)
    {
        // was created
        if (array_key_exists($type, $this->types)) {
            return true;
        }

        // can be created
        $spaceId = $this->manager->getSchema()->getSpaceId($type);
        if (array_key_exists($spaceId, $this->property)) {
            return true;
        }

        return false;
    }

    public function remove($type)
    {
        $other = $this->manager->get('property')->find(['type' => $type]);
        if (count($other)) {
            $name = $this->manager->getSchema()->getSpaceName($other[0]->space);
            throw new Exception("Space $name references ".$type);
        }
        $instance = $this->get($type);
        $rows = $instance->getSpace()->select([])->getData();
        if (count($rows)) {
            throw new Exception("Can't remove non-empty space $type");
        }

        foreach (array_reverse($instance->getProperties()) as $property) {
            $instance->removeProperty($property);
        }

        foreach (array_keys($instance->getIndexes()) as $index) {
            $instance->dropIndex($index);
        }

        $sq = $this->manager->get('sequence')->findOne(['space' => $instance->getSpaceId()]);
        if ($sq) {
            $this->manager->remove($sq);
            $this->manager->get('sequence')->flushCache();
        }

        $this->manager->getSchema()->dropSpace($type);
        unset($this->types[$type]);

        $this->manager->forgetRepository($type);
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
        $this->types[$type] = $instance;

        return $instance;
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
