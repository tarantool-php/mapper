<?php

declare(strict_types=1);

namespace Tarantool\Mapper;

use Closure;
use Exception;
use Tarantool\Client\Schema\Criteria;
use Tarantool\Client\Schema\Space as ClientSpace;

class Schema
{
    private $mapper;

    private $names = [];
    private $spaces = [];
    private $params = [];
    private $engines = [];

    public function __construct(Mapper $mapper, $meta = null)
    {
        $this->mapper = $mapper;
        if ($meta) {
            $this->setMeta($meta);
        } else {
            $this->reset();
        }
    }

    public function createSpace(string $space, array $config = []) : Space
    {
        $options = [
            'engine' => 'memtx',
        ];

        foreach (['engine', 'is_local', 'temporary'] as $key) {
            if (array_key_exists($key, $config)) {
                $options[$key] = $config[$key];
            }
        }

        if (!in_array($options['engine'], ['memtx', 'vinyl'])) {
            throw new Exception("Invalid engine ".$options['engine']);
        }

        [$id] = $this->mapper->getClient()->evaluate("
            local space, options = ...
            box.schema.space.create(space, options)
            return box.space[space].id
        ", $space, $options);

        $this->names[$space] = $id;
        $this->engines[$space] = $options['engine'];

        $this->spaces[$id] = new Space($this->mapper, $id, $space, $options['engine']);

        $properties = array_key_exists('properties', $config) ? $config['properties'] : $config;

        if ($properties) {
            $this->spaces[$id]->addProperties($properties);
        }

        return $this->spaces[$id];
    }

    public function getDefaultValue(string $type)
    {
        switch (strtolower($type)) {
            case 'str':
            case 'string':
                return (string) null;

            case 'bool':
            case 'boolean':
                return (bool) null;

            case 'double':
            case 'float':
            case 'number':
                return (float) null;

            case 'integer':
            case 'unsigned':
            case 'num':
            case 'NUM':
                return (int) null;
        }
        throw new Exception("Invalid type $type");
    }

    public function formatValue(string $type, $value)
    {
        if (is_null($value)) {
            return null;
        }
        switch (strtolower($type)) {
            case 'str':
            case 'string':
                return (string) $value;

            case 'double':
            case 'float':
            case 'number':
                return (float) $value;

            case 'bool':
            case 'boolean':
                return (bool) $value;

            case 'integer':
            case 'unsigned':
            case 'num':
            case 'NUM':
                return (int) $value;

            default:
                return $value;
        }
    }

    public function getSpace($id) : Space
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

    public function getSpaceId(string $name) : int
    {
        if (!$this->hasSpace($name)) {
            throw new Exception("No space $name");
        }
        return $this->names[$name];
    }

    public function getSpaces() : array
    {
        foreach ($this->names as $id) {
            $this->getSpace($id);
        }
        return $this->spaces;
    }

    public function hasSpace(string $name) : bool
    {
        return array_key_exists($name, $this->names);
    }

    public function once(string $name, Closure $callback)
    {
        $key = 'mapper-once'.$name;
        $row = $this->mapper->findOne('_schema', ['key' => $key]);
        if (!$row) {
            $this->mapper->create('_schema', ['key' => $key]);
            return $callback($this->mapper);
        }
    }

    public function forgetOnce(string $name)
    {
        $key = 'mapper-once'.$name;
        $row = $this->mapper->findOne('_schema', ['key' => $key]);
        if ($row) {
            $this->mapper->remove($row);
            return true;
        }

        return false;
    }

    public function reset() : self
    {
        $this->names = [];
        $this->engines = [];
    
        $data = $this->mapper->getClient()->getSpace('_vspace')->select(Criteria::allIterator());
        foreach ($data as $tuple) {
            $this->names[$tuple[2]] = $tuple[0];
            $this->engines[$tuple[2]] = $tuple[3];
        }

        foreach ($this->getSpaces() as $space) {
            if (!array_key_exists($space->getName(), $this->names)) {
                unset($this->spaces[$space->getId()]);
            }
        }

        return $this;
    }

    public function getMeta() : array
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

    public function setMeta($meta) : self
    {
        $this->engines = $meta['engines'];
        $this->names = $meta['names'];
        $this->params = $meta['params'];

        return $this;
    }

    private $underscores = [];

    public function toUnderscore(string $input) : string
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

    public function toCamelCase(string $input) : string
    {
        if (!array_key_exists($input, $this->camelcase)) {
            $this->camelcase[$input] = lcfirst(implode('', array_map('ucfirst', explode('_', $input))));
        }
        return $this->camelcase[$input];
    }
}
