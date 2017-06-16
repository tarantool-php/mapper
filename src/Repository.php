<?php

namespace Tarantool\Mapper;

use Exception;
use SplObjectStorage;

class Repository
{
    private $space;
    private $persisted = [];
    private $original = [];
    private $keys;

    private $results = [];

    public function __construct(Space $space)
    {
        $this->space = $space;
        $this->keys = new SplObjectStorage;
    }

    public function create($data)
    {
        $data = (array) $data;
        $class = Entity::class;
        foreach ($this->getMapper()->getPlugins() as $plugin) {
            $entityClass = $plugin->getEntityClass($this->space);
            if ($entityClass) {
                if ($class != Entity::class) {
                    throw new Exception('Entity class override');
                }
                $class = $entityClass;
            }
        }

        if (array_key_exists(0, $data)) {
            $byType = [];
            foreach ($this->space->getFormat() as $row) {
                if (!array_key_exists($row['type'], $byType)) {
                    $byType[$row['type']] = [$row['name']];
                } else {
                    $byType[$row['type']][] = $row['name'];
                }
            }
            $mapping = [
                'is_numeric' => 'unsigned',
                'is_string' => 'str',
                'is_array' => '*',
            ];
            foreach ($data as $k => $v) {
                foreach ($mapping as $function => $type) {
                    if (call_user_func($function, $v)) {
                        if (count($byType[$type]) == 1) {
                            $data[$byType[$type][0]] = $v;
                            unset($data[$k]);
                        }
                    }
                }
            }
        }

        $instance = new $class($this);

        foreach ($this->space->getFormat() as $row) {
            if (array_key_exists($row['name'], $data)) {
                $instance->{$row['name']} = $data[$row['name']];
                if ($data[$row['name']] instanceof Entity) {
                    $instance->{$row['name']} = $instance->{$row['name']}->id;
                }
            }
        }

        foreach ($this->getMapper()->getPlugins() as $plugin) {
            $plugin->generateKey($instance, $this->space);
            $plugin->afterInstantiate($instance, $this->space);
        }

        // validate instance key
        $key = $this->space->getInstanceKey($instance);

        foreach ($this->keys as $_) {
            if ($this->keys[$_] == $key) {
                throw new Exception($this->space->getName().' '.json_encode($key).' exists');
            }
        }

        $this->keys[$instance] = $key;
        return $instance;
    }

    public function findOne($params = [])
    {
        return $this->find($params, true);
    }

    public function findOrCreate($params = [])
    {
        $entity = $this->findOne($params);
        if (!$entity) {
            $entity = $this->create($params);
        }
        return $entity;
    }

    public function find($params = [], $one = false)
    {
        $cacheKey = json_encode(func_get_args());

        if (array_key_exists($cacheKey, $this->results)) {
            return $this->results[$cacheKey];
        }

        if (!is_array($params)) {
            $params = [$params];
        }
        if (count($params) == 1 && array_key_exists(0, $params)) {
            $primary = $this->space->getPrimaryIndex();
            if (count($primary->parts) == 1) {
                $formatted = $this->getMapper()->getSchema()->formatValue($primary->parts[0][1], $params[0]);
                if ($params[0] == $formatted) {
                    $params = [
                        $this->space->getFormat()[$primary->parts[0][0]]['name'] => $params[0]
                    ];
                }
            }
        }

        if (array_key_exists('id', $params)) {
            if (array_key_exists($params['id'], $this->persisted)) {
                $instance = $this->persisted[$params['id']];
                return $one ? $instance : [$instance];
            }
        }


        $index = $this->space->castIndex($params);
        if (is_null($index)) {
            throw new Exception("No index for params ".json_encode($params));
        }

        $client = $this->getMapper()->getClient();
        $values = $this->space->getIndexValues($index, $params);

        $data = $client->getSpace($this->space->getId())->select($values, $index)->getData();

        $result = [];
        foreach ($data as $tuple) {
            $instance = $this->getInstance($tuple);
            if ($one) {
                return $this->results[$cacheKey] = $instance;
            }
            $result[] = $instance;
        }

        if ($one) {
            return $this->results[$cacheKey] = null;
        }

        return $this->results[$cacheKey] = $result;
    }

    public function forget($id)
    {
        if (array_key_exists($id, $this->persisted)) {
            unset($this->persisted[$id]);
        }
    }

    private function getInstance($tuple)
    {
        $key = $this->space->getTupleKey($tuple);

        if (array_key_exists($key, $this->persisted)) {
            return $this->persisted[$key];
        }

        $class = Entity::class;
        foreach ($this->getMapper()->getPlugins() as $plugin) {
            $entityClass = $plugin->getEntityClass($this->space);
            if ($entityClass) {
                if ($class != Entity::class) {
                    throw new Exception('Entity class override');
                }
                $class = $entityClass;
            }
        }

        $instance = new $class($this);

        $this->original[$key] = $tuple;

        foreach ($this->space->getFormat() as $index => $info) {
            $instance->{$info['name']} = array_key_exists($index, $tuple) ? $tuple[$index] : null;
        }

        $this->keys->offsetSet($instance, $key);

        foreach ($this->getMapper()->getPlugins() as $plugin) {
            $plugin->afterInstantiate($instance);
        }

        return $this->persisted[$key] = $instance;
    }

    public function getMapper()
    {
        return $this->space->getMapper();
    }

    public function knows($instance)
    {
        return $this->keys->offsetExists($instance);
    }

    public function update(Entity $instance, $operations)
    {
        if (!count($operations)) {
            return;
        }

        $tupleOperations = [];
        foreach ($operations as $operation) {
            $tupleIndex = $this->space->getPropertyIndex($operation[1]);
            $tupleOperations[] = [$operation[0], $tupleIndex, $operation[2]];
        }

        $pk = [];
        foreach ($this->space->getPrimaryIndex()->parts as $part) {
            $pk[] = $instance->{$this->space->getFormat()[$part[0]]['name']};
        }

        $client = $this->getMapper()->getClient();
        $result = $client->getSpace($this->space->getId())->update($pk, $tupleOperations);
        foreach ($result->getData() as $tuple) {
            foreach ($this->space->getFormat() as $index => $info) {
                if (array_key_exists($index, $tuple)) {
                    $instance->{$info['name']} = $tuple[$index];
                }
            }
        }
    }

    public function truncate()
    {
        $this->results = [];
        $id = $this->space->getId();
        $this->getMapper()->getClient()->evaluate("box.space[$id]:truncate()");
    }

    public function remove($params = [])
    {
        if ($params instanceof Entity) {
            return $this->removeEntity($params);
        }

        if (!count($params)) {
            throw new Exception("Use truncate to flush space");
        }

        foreach ($this->find($params) as $entity) {
            $this->removeEntity($entity);
        }
    }

    public function removeEntity(Entity $instance)
    {
        $key = $this->space->getInstanceKey($instance);

        if (!array_key_exists($key, $this->original)) {
            return;
        }

        if (array_key_exists($key, $this->persisted)) {
            unset($this->persisted[$key]);

            $pk = [];
            foreach ($this->space->getPrimaryIndex()->parts as $part) {
                $pk[] = $this->original[$key][$part[0]];
            }

            foreach ($this->getMapper()->getPlugins() as $plugin) {
                $plugin->beforeRemove($instance, $this->space);
            }

            if (method_exists($instance, 'beforeRemove')) {
                $instance->beforeRemove();
            }

            $this->getMapper()->getClient()
                ->getSpace($this->space->getId())
                ->delete($pk);
        }

        unset($this->original[$key]);
        unset($this->keys[$instance]);

        $this->results = [];
    }

    public function save($instance)
    {
        $key = $this->space->getInstanceKey($instance);
        $client = $this->getMapper()->getClient();

        if (array_key_exists($key, $this->persisted)) {

            // update
            $tuple = $this->getTuple($instance);
            $update = array_diff_assoc($tuple, $this->original[$key]);
            if (!count($update)) {
                return $instance;
            }

            $operations = [];
            foreach ($update as $index => $value) {
                $operations[] = ['=', $index, $value];
            }

            $pk = [];
            foreach ($this->space->getPrimaryIndex()->parts as $part) {
                $pk[] = $this->original[$key][$part[0]];
            }

            foreach ($this->getMapper()->getPlugins() as $plugin) {
                $plugin->beforeUpdate($instance, $this->space);
            }

            if (method_exists($instance, 'beforeUpdate')) {
                $instance->beforeUpdate();
            }

            $client->getSpace($this->space->getId())->update($pk, $operations);
            $this->original[$key] = $tuple;
        } else {
            $this->addDefaultValues($instance);
            foreach ($this->getMapper()->getPlugins() as $plugin) {
                $plugin->beforeCreate($instance, $this->space);
            }

            if (method_exists($instance, 'beforeCreate')) {
                $instance->beforeCreate();
            }

            $tuple = $this->getTuple($instance);
            $client->getSpace($this->space->getId())->insert($tuple);
            $this->persisted[$key] = $instance;
            $this->original[$key] = $tuple;
        }

        $this->flushCache();

        return $instance;
    }

    private function addDefaultValues(Entity $instance)
    {
        $format = $this->space->getFormat();

        // complete indexes fields
        foreach ($this->space->getIndexes() as $index) {
            foreach ($index->parts as $part) {
                $name = $format[$part[0]]['name'];
                if (!property_exists($instance, $name)) {
                    $instance->{$name} = null;
                }
            }
        }
    }

    public  function getOriginal($instance)
    {
        return $this->original[$this->space->getInstanceKey($instance)];
    }

    private function getTuple(Entity $instance)
    {
        $tuple = [];

        $size = count(get_object_vars($instance));
        $skipped = 0;

        foreach ($this->space->getFormat() as $index => $info) {
            if (!property_exists($instance, $info['name'])) {
                $skipped++;
                $instance->{$info['name']} = null;
            }

            $instance->{$info['name']} = $this->getMapper()->getSchema()
                ->formatValue($info['type'], $instance->{$info['name']});
            $tuple[$index] = $instance->{$info['name']};

            if (count($tuple) == $size + $skipped) {
                break;
            }
        }

        return $tuple;
    }

    public function sync($id, $fields = null)
    {
        if (array_key_exists($id, $this->persisted)) {
            $tuple = $this->getMapper()->getClient()->getSpace($this->space->getId())->select([$id], 0)->getData()[0];

            foreach ($this->space->getFormat() as $index => $info) {
                if (!$fields || in_array($info['name'], $fields)) {
                    $value = array_key_exists($index, $tuple) ? $tuple[$index] : null;
                    $this->persisted[$id]->{$info['name']} = $value;
                    $this->original[$id][$index] = $value;
                }
            }
        }
    }

    public function flushCache()
    {
        $this->results = [];
    }
}
