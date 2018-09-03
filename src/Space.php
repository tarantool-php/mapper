<?php

namespace Tarantool\Mapper;

use Exception;
use Tarantool\Client\Schema\Space as ClientSpace;

class Space
{
    private $mapper;

    private $id;
    private $name;
    private $engine;
    private $format;
    private $indexes;

    private $formatNamesHash = [];
    private $formatTypesHash = [];
    private $formatReferences = [];

    private $repository;

    public function __construct(Mapper $mapper, $id, $name, $engine, $meta = null)
    {
        $this->mapper = $mapper;
        $this->id = $id;
        $this->name = $name;
        $this->engine = $engine;

        if ($meta) {
            foreach ($meta as $key => $value) {
                $this->$key = $value;
            }
        }
    }

    public function getEngine()
    {
        return $this->engine;
    }

    public function addProperties($config)
    {
        foreach ($config as $name => $type) {
            $this->addProperty($name, $type);
        }
        return $this;
    }

    public function addProperty($name, $type, $opts = [])
    {
        $format = $this->getFormat();

        if ($this->getProperty($name, false)) {
            throw new Exception("Property $name exists");
        }

        $row = array_merge(compact('name', 'type'), $opts);
        if (!array_key_exists('is_nullable', $row)) {
            $row['is_nullable'] = true;
        }

        if (array_key_exists('default', $row)) {
            $row['defaultValue'] = $row['default'];
            unset($row['default']);
        }

        $format[] = $row;

        return $this->setFormat($format);
    }

    public function hasDefaultValue($name)
    {
        return array_key_exists('defaultValue', $this->getProperty($name));
    }

    public function getDefaultValue($name)
    {
        return $this->getPropertyFlag($name, 'defaultValue');
    }

    public function isPropertyNullable($name)
    {
        return $this->getPropertyFlag($name, 'is_nullable');
    }

    public function setFormat($format)
    {
        $this->format = $format;
        $this->mapper->getClient()->evaluate("box.space[$this->id]:format(...)", [$format]);
        return $this->parseFormat();
    }

    public function setPropertyNullable($name, $nullable = true)
    {
        $format = $this->getFormat();
        foreach ($format as $i => $field) {
            if ($field['name'] == $name) {
                $format[$i]['is_nullable'] = $nullable;
            }
        }

        return $this->setFormat($format);
    }

    public function removeProperty($name)
    {
        $format = $this->getFormat();
        $last = array_pop($format);
        if ($last['name'] != $name) {
            throw new Exception("Remove only last property");
        }

        return $this->setFormat($format);
    }

    public function removeIndex($name)
    {
        $this->mapper->getClient()->evaluate("box.space[$this->id].index.$name:drop()");
        $this->indexes = [];
        $this->mapper->getRepository('_vindex')->flushCache();

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
            $this->setPropertyNullable($property, false);
        }

        $name = array_key_exists('name', $config) ? $config['name'] : implode('_', $config['fields']);

        $this->mapper->getClient()->evaluate("box.space[$this->id]:create_index('$name', ...)", [$options]);
        $this->indexes = [];

        $this->mapper->getSchema()->getSpace('_vindex')->getRepository()->flushCache();

        return $this;
    }

    public function getIndex($id)
    {
        foreach ($this->getIndexes() as $index) {
            if ($index['iid'] == $id) {
                return $index;
            }
        }

        throw new Exception("Invalid index #$index");
    }

    public function getIndexType($id)
    {
        return $this->getIndex($id)['type'];
    }

    public function isSpecial()
    {
        return $this->id == ClientSpace::VSPACE || $this->id == ClientSpace::VINDEX;
    }

    public function isSystem()
    {
        return $this->id < 512;
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
                    ->getSpace(ClientSpace::VSPACE)->select([$this->id])->getData()[0][6];
            } else {
                $this->format = $this->mapper->findOne('_vspace', ['id' => $this->id])->format;
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
        $this->formatReferences = [];
        foreach ($this->format as $key => $row) {
            $name = $row['name'];
            $this->formatTypesHash[$name] = $row['type'];
            $this->formatNamesHash[$name] = $key;
            if (array_key_exists('reference', $row)) {
                $this->formatReferences[$name] = $row['reference'];
            }
        }
        return $this;
    }

    public function hasProperty($name)
    {
        $this->getFormat();
        return array_key_exists($name, $this->formatNamesHash);
    }

    public function getMeta()
    {
        $this->getFormat();
        $this->getIndexes();

        return [
            'formatNamesHash' => $this->formatNamesHash,
            'formatTypesHash' => $this->formatTypesHash,
            'formatReferences' => $this->formatReferences,
            'indexes' => $this->indexes,
            'format' => $this->format,
        ];
    }

    public function getProperty($name, $required = true)
    {
        foreach ($this->getFormat() as $field) {
            if ($field['name'] == $name) {
                return $field;
            }
        }

        if ($required) {
            throw new Exception("Invalid property $name");
        }
    }

    public function getPropertyFlag($name, $flag)
    {
        $property = $this->getProperty($name);
        if (array_key_exists($flag, $property)) {
            return $property[$flag];
        }
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

    public function getReference($name)
    {
        return $this->isReference($name) ? $this->formatReferences[$name] : null;
    }

    public function isReference($name)
    {
        return array_key_exists($name, $this->formatReferences);
    }

    public function getIndexes()
    {
        if (!$this->indexes) {
            if ($this->isSpecial()) {
                $this->indexes = [];
                $indexTuples = $this->mapper->getClient()->getSpace(ClientSpace::VINDEX)->select([$this->id])->getData();
                $indexFormat = $this->mapper->getSchema()->getSpace(ClientSpace::VINDEX)->getFormat();
                foreach ($indexTuples as $tuple) {
                    $instance = [];
                    foreach ($indexFormat as $index => $format) {
                        $instance[$format['name']] = $tuple[$index];
                    }
                    $this->indexes[] = $instance;
                }
            } else {
                $indexes = $this->mapper->find('_vindex', ['id' => $this->id]);
                $this->indexes = [];
                foreach ($indexes as $index) {
                    $index = get_object_vars($index);
                    foreach ($index as $key => $value) {
                        if (is_object($value)) {
                            unset($index[$key]);
                        }
                    }
                    $this->indexes[] = $index;
                }
            }
        }
        return $this->indexes;
    }

    public function castIndex($params, $suppressException = false)
    {
        if (!count($this->getIndexes())) {
            return;
        }

        $keys = [];
        foreach ($params as $name => $value) {
            $keys[$this->getPropertyIndex($name)] = $name;
        }

        // equals
        foreach ($this->getIndexes() as $index) {
            $equals = false;
            if (count($keys) == count($index['parts'])) {
                // same length
                $equals = true;
                foreach ($index['parts'] as $part) {
                    $equals = $equals && array_key_exists($part[0], $keys);
                }
            }

            if ($equals) {
                return $index['iid'];
            }
        }

        // index part
        foreach ($this->getIndexes() as $index) {
            $partial = [];
            foreach ($index['parts'] as $n => $part) {
                if (!array_key_exists($part[0], $keys)) {
                    break;
                }
                $partial[] = $keys[$part[0]];
            }

            if (count($partial) == count($keys)) {
                return $index['iid'];
            }
        }

        if (!$suppressException) {
            throw new Exception("No index on ".$this->name.' for ['.implode(', ', array_keys($params)).']');
        }
    }

    public function getIndexValues($indexId, $params)
    {
        $index = $this->getIndex($indexId);
        $format = $this->getFormat();

        $values = [];
        foreach ($index['parts'] as $part) {
            $name = $format[$part[0]]['name'];
            if (!array_key_exists($name, $params)) {
                break;
            }
            $value = $this->mapper->getSchema()->formatValue($part[1], $params[$name]);
            if (is_null($value) && !$this->isPropertyNullable($name)) {
                $value = $this->mapper->getSchema()->getDefaultValue($format[$part[0]]['type']);
            }
            $values[] = $value;
        }
        return $values;
    }

    public function getPrimaryIndex()
    {
        return $this->getIndex(0);
    }

    public function getTupleKey($tuple)
    {
        $key = [];
        foreach ($this->getPrimaryIndex()['parts'] as $part) {
            $key[] = $tuple[$part[0]];
        }
        return count($key) == 1 ? $key[0] : implode(':', $key);
    }

    public function getInstanceKey($instance)
    {
        $key = [];

        foreach ($this->getPrimaryIndex()['parts'] as $part) {
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
