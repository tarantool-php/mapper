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

    private $cache = [];
    private $results = [];

    public function __construct(Space $space)
    {
        $this->space = $space;
        $this->keys = new SplObjectStorage;
    }

    public function create($data)
    {
        $class = Entity::class;
        foreach($this->space->getMapper()->getPlugins() as $plugin) {
            $entityClass = $plugin->getEntityClass($this->space);
            if($entityClass) {
                if($class != Entity::class) {
                    throw new Exception('Entity class override');
                }
                $class = $entityClass;
            }
        }
        $instance = new $class();
        foreach($this->space->getFormat() as $row) {
            if(array_key_exists($row['name'], $data)) {
                $instance->{$row['name']} = $data[$row['name']];
            }
        }

        foreach($this->space->getMapper()->getPlugins() as $plugin) {
            $plugin->generateKey($instance, $this->space);
        }

        // validate instance key
        $key = $this->space->getInstanceKey($instance);

        $this->keys[$instance] = $key;
        return $instance;
    }

    public function findOne($params = [])
    {
        return $this->find($params, true);
    }

    public function find($params = [], $one = false)
    {
        $cacheIndex = array_search([$params, $one], $this->cache);
        if($cacheIndex !== false) {
            return $this->results[$cacheIndex];
        }

        if(!is_array($params)) {
            $params = [$params];
        }
        if(count($params) == 1 && array_key_exists(0, $params)) {
            $primary = $this->space->getPrimaryIndex();
            if(count($primary->parts) == 1) {
                $formatted = $this->space->getMapper()->getSchema()->formatValue($primary->parts[0][1], $params[0]);
                if($params[0] == $formatted) {
                    $params = [
                        $this->space->getFormat()[$primary->parts[0][0]]['name'] => $params[0]
                    ];
                }
            }
        }

        if(array_key_exists('id', $params)) {
            if(array_key_exists($params['id'], $this->persisted)) {
                $instance = $this->persisted[$params['id']];
                return $one ? $instance : [$instance];
            }
        }


        $index = $this->space->castIndex($params);
        if(is_null($index)) {
            throw new Exception("No index for params ".json_encode($params));
        }

        $cacheIndex = count($this->cache);
        $this->cache[] = [$params, $one];

        $client = $this->space->getMapper()->getClient();
        $values = $this->space->getIndexValues($index, $params);

        $data = $client->getSpace($this->space->getId())->select($values, $index)->getData();

        $result = [];
        foreach($data as $tuple) {
            $instance = $this->getInstance($tuple);
            if($one) {
                return $this->results[$cacheIndex] = $instance;
            }
            $result[] = $instance;
        }

        if($one) {
            return $this->results[$cacheIndex] = null;
        }

        return $this->results[$cacheIndex] = $result;
    }

    private function getInstance($tuple)
    {
        $key = $this->space->getTupleKey($tuple);

        if(array_key_exists($key, $this->persisted)) {
            return $this->persisted[$key];
        }

        $class = Entity::class;
        foreach($this->space->getMapper()->getPlugins() as $plugin) {
            $entityClass = $plugin->getEntityClass($this->space);
            if($entityClass) {
                if($class != Entity::class) {
                    throw new Exception('Entity class override');
                }
                $class = $entityClass;
            }
        }
        $instance = new $class();

        $this->original[$key] = $tuple;

        foreach($this->space->getFormat() as $index => $info) {
            $instance->{$info['name']} = array_key_exists($index, $tuple) ? $tuple[$index] : null;
        }

        $this->keys->offsetSet($instance, $key);

        return $this->persisted[$key] = $instance;
    }

    public function knows($instance)
    {
        return $this->keys->offsetExists($instance);
    }

    public function update(Entity $instance, $operations)
    {
        if(!count($operations)) {
            return;
        }

        $tupleOperations = [];
        foreach($operations as $operation) {
            $tupleIndex = $this->space->getPropertyIndex($operation[1]);
            $tupleOperations[] = [$operation[0], $tupleIndex, $operation[2]];
        }

        $pk = [];
        foreach($this->space->getPrimaryIndex()->parts as $part) {
            $pk[] = $instance->{$this->space->getFormat()[$part[0]]['name']};
        }

        $client = $this->space->getMapper()->getClient();
        $result = $client->getSpace($this->space->getId())->update($pk, $tupleOperations);
        foreach($result->getData() as $tuple) {
            foreach($this->space->getFormat() as $index => $info) {
                if(array_key_exists($index, $tuple)) {
                    $instance->{$info['name']} = $tuple[$index];
                }
            }
        }
    }

    public function truncate()
    {
        $this->cache = [];
        $this->results = [];
        $id = $this->space->getId();
        $this->space->getMapper()->getClient()->evaluate("box.space[$id]:truncate()");
    }

    public function remove($params = [])
    {
        if($params instanceof Entity) {
            return $this->removeEntity($params);
        }

        if(!count($params)) {
            throw new Exception("Use truncate to flush space");
        }

        foreach($this->find($params) as $entity) {
            $this->removeEntity($entity);
        }
    }

    public function removeEntity(Entity $instance)
    {
        $key = $this->space->getInstanceKey($instance);

        if(!array_key_exists($key, $this->original)) {
            return;
        }

        if(array_key_exists($key, $this->persisted)) {

            unset($this->persisted[$key]);

            $pk = [];
            foreach($this->space->getPrimaryIndex()->parts as $part) {
                $pk[] = $this->original[$key][$part[0]];
            }
            foreach($this->space->getMapper()->getPlugins() as $plugin) {
                $plugin->beforeRemove($instance, $this->space);
            }

            $this->space->getMapper()->getClient()
                ->getSpace($this->space->getId())
                ->delete($pk);
        }

        unset($this->original[$key]);

        $this->results = [];
        $this->cache = [];
    }

    public function save($instance)
    {
        $tuple = [];

        $size = count(get_object_vars($instance));
        $skipped = 0;

        foreach($this->space->getFormat() as $index => $info) {
            if(!property_exists($instance, $info['name'])) {
                $skipped++;
                $instance->{$info['name']} = null;
            }

            $instance->{$info['name']} = $this->space->getMapper()->getSchema()
                ->formatValue($info['type'], $instance->{$info['name']});
            $tuple[$index] = $instance->{$info['name']};

            if(count($tuple) == $size + $skipped) {
                break;
            }
        }

        $key = $this->space->getInstanceKey($instance);
        $client = $this->space->getMapper()->getClient();

        if(array_key_exists($key, $this->persisted)) {
            // update
            $update = array_diff_assoc($tuple, $this->original[$key]);
            if(!count($update)) {
                return $instance;
            }

            $operations = [];
            foreach($update as $index => $value) {
                $operations[] = ['=', $index, $value];
            }

            $pk = [];
            foreach($this->space->getPrimaryIndex()->parts as $part) {
                $pk[] = $this->original[$key][$part[0]];
            }

            foreach($this->space->getMapper()->getPlugins() as $plugin) {
                $plugin->beforeUpdate($instance, $this->space);
            }

            $client->getSpace($this->space->getId())->update($pk, $operations);
            $this->original[$key] = $tuple;

        } else {

            foreach($this->space->getMapper()->getPlugins() as $plugin) {
                $plugin->beforeCreate($instance, $this->space);
            }

            $client->getSpace($this->space->getId())->insert($tuple);
            $this->persisted[$key] = $instance;
            $this->original[$key] = $tuple;
        }


        $this->flushCache();
    }

    public function flushCache()
    {
        $this->cache = [];
    }
}
