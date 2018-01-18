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
            $this->setMeta($meta);
        } else {
            $this->reset();
        }
    }

    public function createSpace($space, $config = [])
    {
        $engine = 'memtx';
        if (array_key_exists('properties', $config)) {
            if (array_key_exists('engine', $config)) {
                $engine = $config['engine'];
                if (!in_array($engine, ['memtx', 'vinyl'])) {
                    throw new Exception("Invalid engine $engine");
                }
            }
        }

        $id = $this->mapper->getClient()->evaluate("
            box.schema.space.create('$space', {
                engine = '$engine'
            })
            return box.space.$space.id
        ")->getData()[0];

        $this->names[$space] = $id;
        $this->engines[$space] = $engine;

        $this->spaces[$id] = new Space($this->mapper, $id, $space, $engine);

        $properties = array_key_exists('properties', $config) ? $config['properties'] : $config;

        if ($properties) {
            $this->spaces[$id]->addProperties($properties);
        }

        return $this->spaces[$id];
    }

    public function getDefaultValue($type)
    {
        switch ($type) {
            case 'STR':
            case 'STRING':
            case 'str':
            case 'string':
                return (string) null;

            case 'double':
            case 'float':
            case 'number':
                return (float) null;

            case 'unsigned':
            case 'UNSIGNED':
            case 'num':
            case 'NUM':
                return (int) null;
        }
        throw new Exception("Invalid type $type");
    }

    public function formatValue($type, $value)
    {
        if (is_null($value)) {
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
            $engine = $this->engines[$name];
            $this->spaces[$id] = new Space($this->mapper, $id, $name, $engine, $meta);
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
        $key = 'mapper-once' . $name;

        $rows = $this->mapper->find('_schema', ['key' => $key]);
        if (!count($rows)) {
            $this->mapper->create('_schema', ['key' => $key]);
            return $callback($this->mapper);
        }
    }

    public function reset()
    {
        [$this->names, $this->engines] = $this->mapper->getClient()->evaluate("
            local spaces = {}
            local engines = {}
            local i, s
            for i, s in box.space._vspace:pairs() do
                spaces[s[3]] = s[1]
                engines[s[3]] = s[4]
            end
            return spaces, engines
        ")->getData();
    }

    public function getMeta()
    {
        $params = [];
        foreach ($this->getSpaces() as $space) {
            $params[$space->getId()] = $space->getMeta();
        }

        return [
            'engines' => $this->engines,
            'names' => $this->names,
            'params' => $params,
        ];
    }

    public function setMeta($meta)
    {
        $this->engines = $meta['engines'];
        $this->names = $meta['names'];
        $this->params = $meta['params'];
    }

    private $underscores = [];

    public function toUnderscore($input)
    {
        if (!array_key_exists($input, $this->underscores)) {
            preg_match_all('!([A-Z][A-Z0-9]*(?=$|[A-Z][a-z0-9])|[A-Za-z][a-z0-9]+)!', $input, $matches);
            $ret = $matches[0];
            foreach ($ret as &$match) {
                $match = $match == strtoupper($match) ? strtolower($match) : lcfirst($match);
            }
            $this->underscores[$input] = implode('_', $ret);
        }
        return $this->underscores[$input];
    }

    private $camelcase = [];

    public function toCamelCase($input)
    {
        if (!array_key_exists($input, $this->camelcase)) {
            $this->camelcase[$input] = lcfirst(implode('', array_map('ucfirst', explode('_', $input))));
        }
        return $this->camelcase[$input];
    }
}
