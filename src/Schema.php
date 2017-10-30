<?php

namespace Tarantool\Mapper;

use Exception;

class Schema
{
    private $mapper;

    private $names = [];
    private $spaces = [];
    private $params = [];

    public function __construct(Mapper $mapper, $meta = null)
    {
        $this->mapper = $mapper;
        if ($meta) {
            $this->names = $meta['names'];
            $this->params = $meta['params'];
        } else {
            $this->reset();
        }
    }

    public function createSpace($space, $properties = null)
    {
        $id = $this->mapper->getClient()->evaluate("
            box.schema.space.create('$space')
            return box.space.$space.id
        ")->getData()[0];

        $this->names[$space] = $id;

        $this->spaces[$id] = new Space($this->mapper, $id, $space);

        if ($properties) {
            $this->spaces[$id]->addProperties($properties);
        }

        return $this->spaces[$id];
    }

    public function formatValue($type, $value)
    {
        if(is_null($value)) {
            return null;
        }
        switch ($type) {
            case 'STR':
            case 'STRING':
            case 'str':
            case 'string':
                return (string) $value;

            case 'double':
            case 'float':
            case 'number':
                return (float) $value;

            case 'unsigned':
            case 'UNSIGNED':
            case 'num':
            case 'NUM':
                return (int) $value;

            default:
                return $value;
        }
    }

    public function getSpace($id)
    {
        if (is_string($id)) {
            return $this->getSpace($this->getSpaceId($id));
        }

        if (!$id) {
            throw new Exception("Space id or name not defined");
        }

        if (!array_key_exists($id, $this->spaces)) {
            $name = array_search($id, $this->names);
            $meta = array_key_exists($id, $this->params) ? $this->params[$id] : null;
            $this->spaces[$id] = new Space($this->mapper, $id, $name, $meta);
        }
        return $this->spaces[$id];
    }

    public function getSpaceId($name)
    {
        if (!$this->hasSpace($name)) {
            throw new Exception("No space $name");
        }
        return $this->names[$name];
    }

    public function getSpaces()
    {
        foreach ($this->names as $id) {
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
        if (!count($rows)) {
            $this->mapper->create('_schema', ['key' => $key]);
            return $callback($this->mapper);
        }
    }

    public function reset()
    {
        $this->names = $this->mapper->getClient()->evaluate("
            local spaces = {}
            local i, s
            for i, s in box.space._vspace:pairs() do
                spaces[s[3]] = s[1]
            end
            return spaces
        ")->getData()[0];
    }

    public function getMeta()
    {
        $params = [];
        foreach ($this->getSpaces() as $space) {
            $params[$space->getId()] = $space->getMeta();
        }

        return [
            'names' => $this->names,
            'params' => $params
        ];
    }
}
