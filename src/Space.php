<?php

namespace Tarantool\Mapper;

use Exception;

class Space
{
    private $mapper;
    
    private $id;
    private $format;
    private $indexes;

    private $repository;

    public function __construct(Mapper $mapper, $id)
    {
        $this->mapper = $mapper;
        $this->id = $id;
    }

    public function addProperty($name, $type)
    {
        $format = $this->getFormat();
        $format[] = compact('name', 'type');
        $this->format = $format;
        $this->mapper->getClient()->evaluate("box.space[$this->id]:format(...)", [$format]);
    }

    public function createIndex($config)
    {

        if(!is_array($config)) {
            $config = ['fields' => $config];
        }
        

        if(!array_key_exists('fields', $config)) {
            if(array_values($config) != $config) {
                throw new Exception("Invalid index configuration");
            }
            $config['fields'] = $config;
        }

        if(!is_array($config['fields'])) {
            $config['fields'] = [$config['fields']];
        }

        $options = [
            'parts' => []
        ];

        foreach($config as $k => $v) {
            if($k != 'name' && $k != 'fields') {
                $options[$k] = $v;
            }
        }
        foreach($config['fields'] as $field) {
            $options['parts'][] = $this->getPropertyIndex($field)+1;
            $options['parts'][] = $this->getPropertyType($field);
        }

        $name = array_key_exists('name', $config) ? $config['name'] : implode('_', $config['fields']);

        $this->mapper->getClient()->evaluate("box.space[$this->id]:create_index('$name', ...)", [$options]);
        $this->indexes = [];

    }

    public function isSpecial()
    {
        return $this->id == 280 || $this->id == 288;
    }

    public function getMapper()
    {
        return $this->mapper;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getFormat()
    {
        if(!$this->format) {
            if($this->isSpecial()) {
                $this->format = $this->mapper->getClient()
                    ->getSpace(280)->select([$this->id])->getData()[0][6];

            } else {
                $this->format = $this->mapper->findOne('_space', ['id' => $this->id])->format;
            }
        }

        return $this->format;
    }

    public function hasProperty($name)
    {
        foreach($this->getFormat() as $row) {
            if($row['name'] == $name) {
                return true;
            }
        }
        return false;
    }

    public function getPropertyType($name)
    {
        foreach($this->getFormat() as $row) {
            if($row['name'] == $name) {
                return $row['type'];
            }
        }
    }

    public function getPropertyIndex($name)
    {
        foreach($this->getFormat() as $index => $row) {
            if($row['name'] == $name) {
                return $index;
            }
        }
    }

    public function getIndexes()
    {
        if(!$this->indexes) {
            if($this->isSpecial()) {
                $this->indexes = [];
                $indexTuples = $this->mapper->getClient()->getSpace(288)->select([$this->id])->getData();
                $indexFormat = $this->mapper->getSchema()->getSpace(288)->getFormat();
                foreach($indexTuples as $tuple) {
                    $instance = (object) [];
                    foreach($indexFormat as $index => $format) {
                        $instance->{$format['name']} = $tuple[$index];
                    }
                    $this->indexes[] = $instance;
                }

            } else {
                $this->indexes = $this->mapper->find('_index', ['id' => $this->id]);
            }
        }
        return $this->indexes;
    }

    public function getIndex($params)
    {
        $keys = array_keys($params);

        $keys = [];
        foreach($params as $name => $value) {
            $keys[] = $this->getPropertyIndex($name);
        }
        if($keys == [0]) {
            // primary index
            return 0;
        }

        // equals
        foreach($this->getIndexes() as $index) {
            $equals = false;
            if(count($keys) == count($index->parts)) {
                // same length
                $equals = true;
                foreach($index->parts as $part) {
                    $equals = $equals && in_array($part[0], $keys);
                }
            }
            
            if($equals) {
                return $index->iid;
            }
        }

        // index part
        foreach($this->getIndexes() as $index) {
            $partial = [];
            foreach($index->parts as $n => $part) {
                if(!array_key_exists($n, $keys)) {
                    break;
                }
                if($keys[$n] != $part[0]) {
                    break;
                }
                $partial[] = $keys[$n];
            }

            if(count($partial) == count($keys)) {
                return $index->iid;
            }
        }

        throw new Exception("No index");
    }

    public function getIndexValues($indexId, $params)
    {
        $index = $this->getIndexes()[$indexId];
        $format = $this->getFormat();

        $values = [];
        foreach($index->parts as $part) {
            $name = $format[$part[0]]['name'];
            if(!array_key_exists($name, $params)) {
                break;
            }
            $values[] = $this->mapper->getSchema()->formatValue($part[1], $params[$name]);
        }
        return $values;
    }

    public function getPrimaryIndex()
    {
        $indexes = $this->getIndexes();
        if(!count($indexes)) {
            throw new Exception("No primary index");
        }
        return $indexes[0];
    }

    public function getTupleKey($tuple)
    {
        $key = [];
        foreach($this->getPrimaryIndex()->parts as $part) {
            $key[] = $tuple[$part[0]];
        }
        return count($key) == 1 ? $key[0] : implode(':', $key);
    }

    public function getInstanceKey($instance)
    {

        $key = [];

        foreach($this->getPrimaryIndex()->parts as $part) {
            $name = $this->getFormat()[$part[0]]['name'];
            if(!property_exists($instance, $name)) {
                throw new Exception("Field $name is undefined", 1);
            }
            $key[] = $instance->$name;
        }

        return count($key) == 1 ? $key[0] : implode(':', $key);
    }

    public function getRepository()
    {
        return $this->repository ?: $this->repository = new Repository($this);
    }
}