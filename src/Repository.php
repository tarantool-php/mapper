<?php

declare(strict_types=1);

namespace Tarantool\Mapper;

use Exception;
use SplObjectStorage;
use Tarantool\Client\Schema\Criteria;
use Tarantool\Client\Schema\Operations;
use Tarantool\Mapper\Plugin\Procedure;
use Tarantool\Mapper\Procedure\FindOrCreate;

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

    public function create($data) : Entity
    {
        $data = (array) $data;
        $class = Entity::class;
        foreach ($this->getMapper()->getPlugins() as $plugin) {
            $entityClass = $plugin->getEntityClass($this->space, $data);
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
                'is_string' => 'string',
                'is_array' => '*',
            ];
            foreach ($data as $k => $v) {
                foreach ($mapping as $function => $type) {
                    if (call_user_func($function, $v)) {
                        if (array_key_exists($type, $byType) && count($byType[$type]) == 1) {
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

    public function findOne($params = []) : ?Entity
    {
        return $this->find($params, true);
    }

    public function findOrCreate($params = []) : Entity
    {
        $space = $this->getSpace();

        if ($space->getName() != '_procedure') {

            $result = $this->getMapper()
                ->getPlugin(Procedure::class)
                ->get(FindOrCreate::class)
                ->execute($space, $this->normalize($params));

            if ($result['created']) {
                $this->flushCache();
            }

            $instance = $this->findOrFail($result['key']);
            if ($result['created']) {
                if (method_exists($instance, 'beforeCreate')) {
                    $instance->beforeCreate();
                    $instance->save();
                }
                foreach ($this->getMapper()->getPlugins() as $plugin) {
                    $plugin->beforeCreate($instance, $space);
                }

                foreach ($this->getMapper()->getPlugins() as $plugin) {
                    $plugin->afterCreate($instance, $space);
                }
                if (method_exists($instance, 'afterCreate')) {
                    $instance->afterCreate();
                }
                $this->flushCache();
            }
        }

        $entity = $this->findOne($params);
        if (!$entity) {
            $entity = $this->create($params);
        }
        return $entity;
    }

    public function findOrFail($params = []) : Entity
    {
        $entity = $this->findOne($params);
        if (!$entity) {
            throw new Exception("No ".$this->getSpace()->getName().' found using '.json_encode($params));
        }
        return $entity;
    }

    public function normalize($params) : array
    {
        if (!is_array($params)) {
            $params = [$params];
        }

        if (count($params) == 1 && array_key_exists(0, $params)) {
            $primary = $this->space->getPrimaryIndex();
            if ($key = $this->space->getPrimaryKey()) {
                $index = $this->space->getPrimaryIndex();
                $type = $index['parts'][0][1];
                $formatted = $this->getMapper()->getSchema()->formatValue($type, $params[0]);
                if ($params[0] == $formatted) {
                    $params = [
                        $key => $formatted
                    ];
                }
            }
        }

        return $params;
    }

    public function find($params = [], $one = false)
    {
        $cacheKey = json_encode(func_get_args());

        if (array_key_exists($cacheKey, $this->results)) {
            return $this->results[$cacheKey];
        }

        $params = $this->normalize($params);

        if (array_key_exists('id', $params)) {
            if (array_key_exists($params['id'], $this->persisted)) {
                $instance = $this->persisted[$params['id']];
                return $one ? $instance : [$instance];
            }
        }

        $space = $this->space;
        $index = $space->castIndex($params);
        if (is_null($index)) {
            throw new Exception("No index for params ".json_encode($params));
        }

        $criteria = Criteria::index($index)
            ->andKey($space->getIndexValues($index, $params));

        if ($space->getIndextype($index) == 'hash' && !count($params)) {
            $criteria = $criteria->allIterator();
        }

        $data = $this->getMapper()
            ->getClient()
            ->getSpace($space->getName())
            ->select($criteria);

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

    public function forget(int $id) : self
    {
        if (array_key_exists($id, $this->persisted)) {
            unset($this->persisted[$id]);
        }

        return $this;
    }

    public function getInstance(array $tuple) : Entity
    {
        $key = $this->space->getTupleKey($tuple);

        if (array_key_exists($key, $this->persisted)) {
            return $this->persisted[$key];
        }

        $data = [];
        foreach ($this->space->getFormat() as $index => $info) {
            $data[$info['name']] = array_key_exists($index, $tuple) ? $tuple[$index] : null;
        }

        $class = Entity::class;
        foreach ($this->getMapper()->getPlugins() as $plugin) {
            $entityClass = $plugin->getEntityClass($this->space, $data);
            if ($entityClass) {
                if ($class != Entity::class) {
                    throw new Exception('Entity class override');
                }
                $class = $entityClass;
            }
        }

        $instance = new $class($this);

        $this->original[$key] = $tuple;

        foreach ($data as $k => $v) {
            $instance->$k = $v;
        }

        $this->keys->offsetSet($instance, $key);

        foreach ($this->getMapper()->getPlugins() as $plugin) {
            $plugin->afterInstantiate($instance);
        }

        return $this->persisted[$key] = $instance;
    }

    public function getMapper() : Mapper
    {
        return $this->space->getMapper();
    }

    public function getSpace() : Space
    {
        return $this->space;
    }

    public function knows(Entity $instance) : bool
    {
        return $this->keys->offsetExists($instance);
    }

    public function truncate() : self
    {
        $this->results = [];
        $name = $this->space->getName();
        $this->getMapper()->getClient()->call("box.space.$name:truncate");

        return $this;
    }

    public function remove($params = []) : self
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

        return $this;
    }

    public function removeEntity(Entity $instance) : self
    {
        $key = $this->space->getInstanceKey($instance);

        if (!array_key_exists($key, $this->original)) {
            return $this;
        }

        if (array_key_exists($key, $this->persisted)) {
            unset($this->persisted[$key]);

            $pk = [];
            foreach ($this->space->getPrimaryIndex()['parts'] as $part) {
                $pk[] = $this->original[$key][$part[0]];
            }

            foreach ($this->getMapper()->getPlugins() as $plugin) {
                $plugin->beforeRemove($instance, $this->space);
            }

            if (method_exists($instance, 'beforeRemove')) {
                $instance->beforeRemove();
            }

            $this->getMapper()->getClient()
                ->getSpaceById($this->space->getId())
                ->delete($pk);

            foreach ($this->getMapper()->getPlugins() as $plugin) {
                $plugin->afterRemove($instance, $this->space);
            }

            if (method_exists($instance, 'afterRemove')) {
                $instance->afterRemove();
            }
        }

        unset($this->original[$key]);
        unset($this->keys[$instance]);

        $this->results = [];
        return $this;
    }

    public function save(Entity $instance) : Entity
    {
        $key = $this->space->getInstanceKey($instance);
        $client = $this->getMapper()->getClient();

        if (array_key_exists($key, $this->persisted)) {
            // update
            $tuple = $this->getTuple($instance);
            $update = [];

            foreach ($tuple as $i => $v) {
                if (!array_key_exists($i, $this->original[$key]) || $v !== $this->original[$key][$i]) {
                    $update[$i] = $v;
                }
            }

            if (!count($update)) {
                return $instance;
            }

            foreach ($this->getMapper()->getPlugins() as $plugin) {
                $plugin->beforeUpdate($instance, $this->space);
            }

            if (method_exists($instance, 'beforeUpdate')) {
                $instance->beforeUpdate();
            }

            $tuple = $this->getTuple($instance);
            $update = [];

            foreach ($tuple as $i => $v) {
                if (!array_key_exists($i, $this->original[$key]) || $v !== $this->original[$key][$i]) {
                    $update[$i] = $v;
                }
            }

            if (!count($update)) {
                return $instance;
            }

            $operations = null;
            foreach ($update as $index => $value) {
                $operations = $operations ? $operations->andSet($index, $value) : Operations::set($index, $value);
            }

            $pk = [];
            foreach ($this->space->getPrimaryIndex()['parts'] as $part) {
                $pk[] = $this->original[$key][$part[0]];
            }

            $client->getSpaceById($this->space->getId())->update($pk, $operations);
            $this->original[$key] = $tuple;

            foreach ($this->getMapper()->getPlugins() as $plugin) {
                $plugin->afterUpdate($instance, $this->space);
            }

            if (method_exists($instance, 'afterUpdate')) {
                $instance->afterUpdate();
            }
        } else {
            $this->addDefaultValues($instance);
            foreach ($this->getMapper()->getPlugins() as $plugin) {
                $plugin->beforeCreate($instance, $this->space);
            }

            if (method_exists($instance, 'beforeCreate')) {
                $instance->beforeCreate();
            }

            $tuple = $this->getTuple($instance);
            $client->getSpaceById($this->space->getId())->insert($tuple);
            $this->persisted[$key] = $instance;
            $this->original[$key] = $tuple;

            foreach ($this->getMapper()->getPlugins() as $plugin) {
                $plugin->afterCreate($instance, $this->space);
            }

            if (method_exists($instance, 'afterCreate')) {
                $instance->afterCreate();
            }
        }

        $this->flushCache();

        return $instance;
    }

    private function addDefaultValues(Entity $instance) : Entity
    {
        $format = $this->space->getFormat();

        // complete format fields
        foreach ($format as $info) {
            $name = $info['name'];
            if (!property_exists($instance, $name)) {
                $instance->$name = null;
            }
        }

        return $instance;
    }

    public function getOriginal(Entity $instance) : array
    {
        return $this->original[$this->space->getInstanceKey($instance)];
    }

    private function getTuple(Entity $instance) : array
    {
        $schema = $this->getMapper()->getSchema();
        $tuple = [];

        foreach ($this->space->getFormat() as $index => $info) {
            $name = $info['name'];
            if (!property_exists($instance, $name)) {
                $instance->$name = null;
            }

            $instance->$name = $schema->formatValue($info['type'], $instance->$name);
            if (is_null($instance->$name)) {
                if ($this->space->hasDefaultValue($name)) {
                    $instance->$name = $this->space->getDefaultValue($name);
                } elseif (!$this->space->isPropertyNullable($name)) {
                    $instance->$name = $schema->getDefaultValue($info['type']);
                }
            }

            $tuple[$index] = $instance->$name;
        }

        return $tuple;
    }

    public function sync(int $id, string $fields = null) : ?Entity
    {
        if (array_key_exists($id, $this->persisted)) {
            [$tuple] = $this->getMapper()->getClient()->getSpaceById($this->space->getId())->select(Criteria::key([$id]));

            foreach ($this->space->getFormat() as $index => $info) {
                if (!$fields || in_array($info['name'], $fields)) {
                    $value = array_key_exists($index, $tuple) ? $tuple[$index] : null;
                    $this->persisted[$id]->{$info['name']} = $value;
                    $this->original[$id][$index] = $value;
                }
            }

            return $this->persisted[$id];
        }
    }

    public function flushCache() : self
    {
        $this->results = [];
        return $this;
    }
}
