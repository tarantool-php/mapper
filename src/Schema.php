<?php

namespace Tarantool\Mapper;

use Exception;

class Schema
{
    private $mapper;

    private $names = [];
    private $spaces = [];

    public function __construct(Mapper $mapper)
    {
        $this->mapper = $mapper;
        $this->reset();
    }

    public function createSpace($space, $properties = null)
    {
        $id = $this->mapper->getClient()->evaluate("
            box.schema.space.create('$space')
            return box.space.$space.id
        ")->getData()[0];

        $this->names[$space] = $id;

        $this->spaces[$id] = new Space($this->mapper, $id, $space);

        if($properties) {
            $this->spaces[$id]->addProperties($properties);
        }

        return $this->spaces[$id];
    }

    public function formatValue($type, $value)
    {
        switch($type) {
            case 'STR':
            case 'str':
                return (string) $value;

            case 'unsigned':
            case 'UNSIGNED':
            case 'num':
            case 'NUM':
                return (int) $value;
            default: return $value;
        }
    }

    public function getSpace($id)
    {
        if(is_string($id)) {
            return $this->getSpace($this->getSpaceId($id));
        }

        if(!$id) {
            throw new Exception("Space id or name not defined");
        }

        if(!array_key_exists($id, $this->spaces)) {
            $this->spaces[$id] = new Space($this->mapper, $id, array_search($id, $this->names));
        }
        return $this->spaces[$id];
    }

    public function getSpaceId($name)
    {
        if(!$this->hasSpace($name)) {
            throw new Exception("No space $name");
        }
        return $this->names[$name];
    }

    public function getSpaces()
    {
        foreach($this->names as $id) {
            $this->getSpace($id);
        }
        return $this->spaces;
    }

    public function hasSpace($name)
    {
        return array_key_exists($name, $this->names);
    }

    public function once($name, $callback)
    {
        $key = 'once' . $name;

        $rows = $this->mapper->find('_schema', ['key' => $key]);
        if(!count($rows)) {
            $this->mapper->create('_schema', ['key' => $key]);
            return $callback($this->mapper);
        }
    }

    public function reset()
    {
        $this->names = $this->mapper->getClient()->evaluate("
            local spaces = {}
            local i, s
            for i, s in box.space._space:pairs() do
                spaces[s[3]] = s[1]
            end
            return spaces"
        )->getData()[0];
    }
}
