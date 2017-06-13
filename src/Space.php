<?php

namespace Tarantool\Mapper;

use Exception;

class Space
{
    private $mapper;

    private $id;
    private $name;
    private $format;
    private $indexes;

    private $formatNamesHash = [];
    private $formatTypesHash = [];

    private $repository;

    public function __construct(Mapper $mapper, $id, $name)
    {
        $this->mapper = $mapper;
        $this->id = $id;
        $this->name = $name;
    }

    public function addProperties($config)
    {
        foreach ($config as $name => $type) {
            $this->addProperty($name, $type);
        }
        return $this;
    }

    public function addProperty($name, $type)
    {
        $format = $this->getFormat();
        foreach ($format as $field) {
            if ($field['name'] == $name) {
                throw new Exception("Property $name exists");
            }
        }
        $format[] = compact('name', 'type');
        $this->format = $format;
        $this->mapper->getClient()->evaluate("box.space[$this->id]:format(...)", [$format]);

        $this->parseFormat();

        return $this;
    }

    public function removeProperty($name)
    {
        $format = $this->getFormat();
        $last = array_pop($format);
        if ($last['name'] != $name) {
            throw new Exception("Remove only last property");
        }
        $this->mapper->getClient()->evaluate("box.space[$this->id]:format(...)", [$format]);
        $this->format = $format;

        $this->parseFormat();

        return $this;
    }

    public function removeIndex($name)
    {
        $this->mapper->getClient()->evaluate("box.space[$this->id].index.$name:drop()");
        $this->indexes = [];
        $this->mapper->getRepository('_index')->flushCache();

        return $this;
    }

    public function addIndex($config)
    {
        return $this->createIndex($config);
    }

    public function createIndex($config)
    {
        if (!is_array($config)) {
            $config = ['fields' => $config];
        }


        if (!array_key_exists('fields', $config)) {
            if (array_values($config) != $config) {
                throw new Exception("Invalid index configuration");
            }
            $config = [
                'fields' => $config
            ];
        }

        if (!is_array($config['fields'])) {
            $config['fields'] = [$config['fields']];
        }

        $options = [
            'parts' => []
        ];

        foreach ($config as $k => $v) {
            if ($k != 'name' && $k != 'fields') {
                $options[$k] = $v;
            }
        }

        foreach ($config['fields'] as $property) {
            if (!$this->getPropertyType($property)) {
                throw new Exception("Unknown property $property", 1);
            }
            $options['parts'][] = $this->getPropertyIndex($property)+1;
            $options['parts'][] = $this->getPropertyType($property);
        }

        $name = array_key_exists('name', $config) ? $config['name'] : implode('_', $config['fields']);

        $this->mapper->getClient()->evaluate("box.space[$this->id]:create_index('$name', ...)", [$options]);
        $this->indexes = [];

        $this->mapper->getSchema()->getSpace('_index')->getRepository()->flushCache();

        return $this;
    }

    public function isSpecial()
    {
        return $this->id == 280 || $this->id == 288;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getTupleMap()
    {
        $reverse = [];
        foreach ($this->getFormat() as $i => $field) {
            $reverse[$field['name']] = $i + 1;
        }
        return (object) $reverse;
    }

    public function getFormat()
    {
        if (!$this->format) {
            if ($this->isSpecial()) {
                $this->format = $this->mapper->getClient()
                    ->getSpace(280)->select([$this->id])->getData()[0][6];
            } else {
                $this->format = $this->mapper->findOne('_space', ['id' => $this->id])->format;
            }
            if (!$this->format) {
                $this->format = [];
            }
            $this->parseFormat();
        }

        return $this->format;
    }

    public function getMapper()
    {
        return $this->mapper;
    }

    public function getName()
    {
        return $this->name;
    }

    private function parseFormat()
    {
        $this->formatTypesHash = [];
        $this->formatNamesHash = [];
        foreach ($this->format as $key => $row) {
            $this->formatTypesHash[$row['name']] = $row['type'];
            $this->formatNamesHash[$row['name']] = $key;
        }
        return $this;
    }

    public function hasProperty($name)
    {
        $this->getFormat();
        return array_key_exists($name, $this->formatNamesHash);
    }

    public function getPropertyType($name)
    {
        if (!$this->hasProperty($name)) {
            throw new Exception("No property $name");
        }
        return $this->formatTypesHash[$name];
    }

    public function getPropertyIndex($name)
    {
        if (!$this->hasProperty($name)) {
            throw new Exception("No property $name");
        }
        return $this->formatNamesHash[$name];
    }

    public function getIndexes()
    {
        if (!$this->indexes) {
            if ($this->isSpecial()) {
                $this->indexes = [];
                $indexTuples = $this->mapper->getClient()->getSpace(288)->select([$this->id])->getData();
                $indexFormat = $this->mapper->getSchema()->getSpace(288)->getFormat();
                foreach ($indexTuples as $tuple) {
                    $instance = (object) [];
                    foreach ($indexFormat as $index => $format) {
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

    public function castIndex($params, $suppressException = false)
    {
        if (!count($this->getIndexes())) {
            return;
        }
        $keys = array_keys($params);

        $keys = [];
        foreach ($params as $name => $value) {
            $keys[] = $this->getPropertyIndex($name);
        }

        // equals
        foreach ($this->getIndexes() as $index) {
            $equals = false;
            if (count($keys) == count($index->parts)) {
                // same length
                $equals = true;
                foreach ($index->parts as $part) {
                    $equals = $equals && in_array($part[0], $keys);
                }
            }

            if ($equals) {
                return $index->iid;
            }
        }

        // index part
        foreach ($this->getIndexes() as $index) {
            $partial = [];
            foreach ($index->parts as $n => $part) {
                if (!array_key_exists($n, $keys)) {
                    break;
                }
                if ($keys[$n] != $part[0]) {
                    break;
                }
                $partial[] = $keys[$n];
            }

            if (count($partial) == count($keys)) {
                return $index->iid;
            }
        }

        if (!$suppressException) {
            throw new Exception("No index");
        }
    }

    public function getIndexValues($indexId, $params)
    {
        $index = null;
        foreach ($this->getIndexes() as $candidate) {
            if ($candidate->iid == $indexId) {
                $index = $candidate;
                break;
            }
        }
        if (!$index) {
            throw new Exception("Undefined index: $indexId");
        }

        $format = $this->getFormat();
        $values = [];
        foreach ($index->parts as $part) {
            $name = $format[$part[0]]['name'];
            if (!array_key_exists($name, $params)) {
                break;
            }
            $values[] = $this->mapper->getSchema()->formatValue($part[1], $params[$name]);
        }
        return $values;
    }

    public function getPrimaryIndex()
    {
        $indexes = $this->getIndexes();
        if (!count($indexes)) {
            throw new Exception("No primary index");
        }
        return $indexes[0];
    }

    public function getTupleKey($tuple)
    {
        $key = [];
        foreach ($this->getPrimaryIndex()->parts as $part) {
            $key[] = $tuple[$part[0]];
        }
        return count($key) == 1 ? $key[0] : implode(':', $key);
    }

    public function getInstanceKey($instance)
    {
        $key = [];

        foreach ($this->getPrimaryIndex()->parts as $part) {
            $name = $this->getFormat()[$part[0]]['name'];
            if (!property_exists($instance, $name)) {
                throw new Exception("Field $name is undefined", 1);
            }
            $key[] = $instance->$name;
        }

        return count($key) == 1 ? $key[0] : implode(':', $key);
    }

    public function getRepository()
    {
        $class = Repository::class;
        foreach ($this->mapper->getPlugins() as $plugin) {
            $repositoryClass = $plugin->getRepositoryClass($this);
            if ($repositoryClass) {
                if ($class != Repository::class) {
                    throw new Exception('Repository class override');
                }
                $class = $repositoryClass;
            }
        }
        return $this->repository ?: $this->repository = new $class($this);
    }

    public function repositoryExists()
    {
        return !!$this->repository;
    }
}
