<?php

declare(strict_types=1);

namespace Tarantool\Mapper;

use Exception;
use SplObjectStorage;
use Tarantool\Client\Schema\Criteria;
use Tarantool\Client\Schema\Operations;
use Tarantool\Mapper\Plugin\Procedure;
use Tarantool\Mapper\Procedure\FindOrCreate;

#[\AllowDynamicProperties]
class Repository
{
    public function __construct(
        public readonly Space $space
    ) {
    }

    public function create($data): Entity
    {
        $data = (array) $data;

        if (array_key_exists(0, $data)) {
            $byType = [];
            foreach ($this->getSpace()->getProperties() as $property) {
                if (!array_key_exists($property->type, $byType)) {
                    $byType[$property->type] = [$property->name];
                } else {
                    $byType[$property->type][] = $property->name;
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

        $instance = $this->createInstance([], $data);

        return $instance;
    }

    public function createInstance(array $tuple = [], array $data = []): Entity
    {
        $class = Entity::class;
        $map = $this->getSpace()->getMap($tuple);
        foreach ($this->getMapper()->getPlugins() as $plugin) {
            $entityClass = $plugin->getEntityClass($this->space, $map);
            if (!$entityClass) {
                continue;
            }
            if ($class !== Entity::class && !is_subclass_of($class, Entity::class)) {
                throw new Exception('Entity class override');
            }
            $class = $entityClass;
        }

        $instance = new $class($this, $tuple);

        foreach ($data as $key => $value) {
            if ($value instanceof Entity) {
                $value  = $value->getRepository()->getSpace()->getIndex(0)->getValue($value->toArray()) ?: null;
            }
            $instance->$key = $value;
        }

        foreach ($this->getMapper()->getPlugins() as $plugin) {
            $plugin->afterInstantiate($instance, $this->space);
        }

        return $instance;
    }

    public function find($params = [], $one = false)
    {
        $params = $this->normalize($params);
        $index = $this->getSpace()->castIndex($params);

        if ($index === null) {
            throw new Exception("No index for params " . json_encode($params));
        }

        $criteria = Criteria::index($index->id)
            ->andKey($index->getValues($params));

        if ($index->type == 'hash' && !count($params)) {
            $criteria = $criteria->allIterator();
        }

        if ($one) {
            $criteria = $criteria->andLimit(1);
        }

        $data = $this->getMapper()
            ->getClient()
            ->getSpace($this->getSpace()->name)
            ->select($criteria);

        $result = [];
        foreach ($data as $tuple) {
            $instance = $this->createInstance($tuple);
            if ($one) {
                return $instance;
            }
            $result[] = $instance;
        }

        if ($one) {
            return null;
        }

        return $result;
    }

    public function findOne($params = []): ?Entity
    {
        return $this->find($params, true);
    }

    public function findOrCreate($params = [], $data = []): Entity
    {
        $instance = $this->findOne($params);
        if ($instance !== null) {
            return $instance;
        }

        $space = $this->getSpace();

        if ($space->name != '_procedure') {
            $result = $this->getMapper()
                ->getPlugin(Procedure::class)
                ->get(FindOrCreate::class)
                ->execute($space, $this->normalize($data ?: $params), $this->normalize($params));

            $instance = $this->createInstance($result['tuple']);
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
            }
        }

        $instance = $this->findOne($params);
        if (!$instance) {
            $instance = $this->create($params)->save();
        }
        return $instance;
    }

    public function findOrFail($params = []): Entity
    {
        $instance = $this->findOne($params);
        if (!$instance) {
            throw new Exception("No " . $this->getSpace()->name . ' found using ' . json_encode($params));
        }
        return $instance;
    }

    public function getMapper(): Mapper
    {
        return $this->getSpace()->getMapper();
    }

    public function getSpace(): Space
    {
        return $this->space;
    }

    public function normalize($params): array
    {
        if (!is_array($params)) {
            $params = [$params];
        }

        if (count($params) == 1 && array_key_exists(0, $params)) {
            $property = $this->getSpace()->getIndex(0)->getProperty();
            if ($property) {
                $formatted = Converter::formatValue($property->type, $params[0]);
                if ($params[0] == $formatted) {
                    $params = [
                        $property->name => $formatted,
                    ];
                }
            }
        }

        return $params;
    }

    public function remove($params)
    {
        if ($params instanceof Entity) {
            $instances = [$params];
        } else {
            $instances = $this->find($params);
        }

        $fields = $this->space->getFields();

        foreach ($instances as $instance) {
            $pk = $this->getSpace()->getIndex(0)->getValues($instance->toArray());

            foreach ($this->getMapper()->getPlugins() as $plugin) {
                $plugin->beforeRemove($instance, $this->space);
            }

            $this->getMapper()->getClient()
                ->getSpaceById($this->space->id)
                ->delete($pk);

            foreach ($this->getMapper()->getPlugins() as $plugin) {
                $plugin->afterRemove($instance, $this->space);
            }
        }
    }

    public function save(Entity $instance): Entity
    {
        $client = $this->getMapper()->getClient();

        if ($instance->getOriginalTuple()) {
            // update
            $update = $instance->getTupleChanges();

            if (!count($update)) {
                return $instance;
            }

            foreach ($this->getMapper()->getPlugins() as $plugin) {
                $plugin->beforeUpdate($instance, $this->space);
            }

            if (method_exists($instance, 'beforeUpdate')) {
                $instance->beforeUpdate();
            }

            $update = $instance->getTupleChanges();

            if (!count($update)) {
                return $instance;
            }

            $operations = null;
            foreach ($update as $index => $value) {
                $operations = $operations ? $operations->andSet($index, $value) : Operations::set($index, $value);
            }

            $pk = [];
            $current = $instance->toTuple();
            foreach ($this->getSpace()->getIndex(0)->parts as $part) {
                $pk[] = $current[$part['field']];
            }

            $client->getSpaceById($this->getSpace()->id)->update($pk, $operations);
            $instance->setOriginalTuple($current);

            foreach ($this->getMapper()->getPlugins() as $plugin) {
                $plugin->afterUpdate($instance, $this->space);
            }

            if (method_exists($instance, 'afterUpdate')) {
                $instance->afterUpdate();
            }
        } else {
            foreach ($this->getMapper()->getPlugins() as $plugin) {
                $plugin->generateKey($instance, $this->space);
                $plugin->beforeCreate($instance, $this->space);
            }

            if (method_exists($instance, 'beforeCreate')) {
                $instance->beforeCreate();
            }

            $tuple = $instance->toTuple();
            $client->getSpaceById($this->getSpace()->id)
                ->insert($tuple);

            $instance->setOriginalTuple($tuple);

            foreach ($this->getMapper()->getPlugins() as $plugin) {
                $plugin->afterCreate($instance, $this->space);
            }

            if (method_exists($instance, 'afterCreate')) {
                $instance->afterCreate();
            }
        }

        return $instance;
    }

    public function truncate(): self
    {
        $name = $this->getSpace()->name;
        $this->getMapper()->getClient()->call("box.space.$name:truncate");

        return $this;
    }
}
