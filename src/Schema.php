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
        $this->names = $mapper->getClient()->evaluate("
            local spaces = {}
            local i, s
            for i, s in box.space._space:pairs() do
                spaces[s[3]] = s[1]
            end
            return spaces"
        )->getData()[0];
    }

    public function getSpace($id)
    {
        if(is_string($id)) {
            if(array_key_exists($id, $this->names)) {
                return $this->getSpace($this->names[$id]);
            }
            throw new Exception("No space $id");
        }

        if(!array_key_exists($id, $this->spaces)) {
            $this->spaces[$id] = new Space($this->mapper, $id);
        }
        return $this->spaces[$id];
    }

    public function getSpaces()
    {
        return $this->spaces;
    }

    public function createSpace($space)
    {
        $id = $this->mapper->getClient()->evaluate("
            box.schema.space.create('$space')
            return box.space.$space.id
        ")->getData()[0];

        $this->names[$space] = $id;
        
        return $this->spaces[$id] = new Space($this->mapper, $id);
    }

    public function formatValue($type, $value)
    {
        switch($type) {
            case 'str': return (string) $value;
            case 'unsigned': return (int) $value;
            default: return $value;
        }
    }
}