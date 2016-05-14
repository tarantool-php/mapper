<?php

namespace Tarantool\Mapper;

use BadMethodCallException;
use Exception;
use LogicException;

class Repository implements Contracts\Repository
{
    private $type;
    private $entities = [];
    private $keyMap = [];
    private $findCache = [];
    private $original = [];

    private $magicMethodRules = [
        'by' => false,
        'firstBy' => true,
        'oneBy' => true,
    ];

    public function __construct(Contracts\Type $type)
    {
        $this->type = $type;
    }

    public function create($params = null)
    {
        if ($params && !is_array($params)) {
            $params = [$params];
        }

        if (!is_array($params)) {
            $params = [];
        }

        $data = [];
        foreach ($params as $k => $v) {
            if (is_numeric($k)) {
                if ($v instanceof Contracts\Entity) {
                    $type = $this->type->getManager()->findRepository($v)->getType();
                    $k = $this->type->getReferenceProperty($type);
                } else {
                    $primitive = [];
                    foreach ($this->type->getProperties() as $property) {
                        if (!$this->type->isReference($property)) {
                            $primitive[] = $property;
                        }
                    }
                    if (count($primitive) == 2) {
                        $k = $primitive[1];
                    } else {
                        throw new Exception("Can't calculate key name");
                    }
                }
            }
            if (!$this->type->hasProperty($k)) {
                $name = $this->type->getName();
                throw new Exception("Unknown property $name.$k");
            }
            $data[$k] = $this->type->encodeProperty($k, $v);
        }

        $this->flushCache();

        return $this->createInstance($data);
    }

    private function createInstance($data)
    {
        $class = $this->getType()->getEntityClass();

        return $this->register(new $class($data));
    }

    public function __call($method, $arguments)
    {
        foreach ($this->magicMethodRules as $prefix => $oneItem) {
            if (substr($method, 0, strlen($prefix)) == $prefix) {
                $tail = substr($method, strlen($prefix));
                $fields = array_map('strtolower', explode('And', $tail));

                return $this->find(array_combine($fields, $arguments), $oneItem);
            }
        }

        throw new BadMethodCallException("Method $method not found");
    }

    public function findOne($params)
    {
        return $this->find($params, true);
    }

    public function find($params = [], $oneItem = false)
    {
        $query = [];

        if (is_string($params) && 1 * $params == $params) {
            $params = 1 * $params;
        }

        if (is_int($params)) {
            if (isset($this->keyMap[$params])) {
                return $this->entities[$this->keyMap[$params]];
            }
            $query = [
                'id' => $params,
            ];
            $oneItem = true;
        }

        if ($params instanceof Contracts\Entity) {
            $params = [$params];
        }

        if (is_array($params)) {
            foreach ($params as $key => $value) {
                if (is_numeric($key) && $value instanceof Contracts\Entity) {
                    $type = $this->type->getManager()->findRepository($value)->getType();
                    $key = $this->type->getReferenceProperty($type);
                }
                if ($this->type->hasProperty($key)) {
                    $query[$key] = $this->type->encodeProperty($key, $value);
                }
            }
        }

        $findKey = md5(json_encode($query).($oneItem ? 'x' : ''));
        if (array_key_exists($findKey, $this->findCache)) {
            return $this->findCache[$findKey];
        }

        $index = $this->type->findIndex(array_keys($query));
        if (!is_numeric($index)) {
            throw new Exception('No index found for '.json_encode(array_keys($query)));
        }

        $values = count($query) ? $this->type->getIndexTuple($index, $query) : [];
        $data = $this->type->getSpace()->select($values, $index);

        $result = [];
        if (!empty($data->getData())) {
            foreach ($data->getData() as $tuple) {
                $data = $this->type->fromTuple($tuple);
                if (isset($data['id']) && array_key_exists($data['id'], $this->keyMap)) {
                    $entity = $this->entities[$this->keyMap[$data['id']]];
                    $entity->update($data);
                } else {
                    $entity = $this->createInstance($data);
                }
                if ($oneItem) {
                    return $this->findCache[$findKey] = $entity;
                }
                $result[] = $entity;
            }
        }
        if (!$oneItem) {
            return $this->findCache[$findKey] = $result;
        }
    }

    /**
     * @return Entity
     */
    public function knows(Contracts\Entity $entity)
    {
        return in_array($entity, $this->entities);
    }

    public function remove(Contracts\Entity $entity)
    {
        unset($this->entities[$this->keyMap[$entity->id]]);
        unset($this->keyMap[$entity->id]);
        $this->flushCache();

        $this->type->getSpace()->delete([$entity->id]);
    }

    public function removeAll()
    {
        foreach ($this->find([]) as $entity) {
            $this->remove($entity);
        }
        $this->flushCache();
    }

    public function flushCache()
    {
        $this->findCache = [];
    }

    public function save(Contracts\Entity $entity)
    {
        if (!$this->knows($entity)) {
            throw new LogicException('Entity is not related with this repository');
        }

        if (!$entity->getId()) {
            $this->generateId($entity);
            $tuple = $this->type->getCompleteTuple($entity->toArray());
            $this->type->getSpace()->insert($tuple);
        } else {
            $array = $entity->toArray(false);
            $changes = [];
            $id = $entity->getId();
            if (!array_key_exists($id, $this->original)) {
                $changes = $array;
            } else {
                foreach ($array as $k => $v) {
                    if (!array_key_exists($k, $this->original[$id])) {
                        $changes[$k] = $v;
                    } elseif ($v !== $this->original[$id][$k]) {
                        $changes[$k] = $v;
                    }
                }
            }

            foreach ($changes as $k => $v) {
                if (!$this->type->hasProperty($k)) {
                    $name = $this->type->getName();
                    throw new Exception("Unknown property $name.$k");
                }
            }

            if (count($changes)) {
                $operations = [];
                foreach ($this->type->getTuple($changes) as $key => $value) {
                    $operations[] = ['=', $key, $value];
                }
                try {
                    $this->type->getSpace()->update($id, $operations);
                } catch (Exception $e) {
                    $this->type->getSpace()->delete([$id]);
                    $tuple = $this->type->getCompleteTuple($entity->toArray());
                    $this->type->getSpace()->insert($tuple);
                }
                $this->original[$id] = $entity->toArray();
            }
        }

        return $entity;
    }

    private function register(Contracts\Entity $entity)
    {
        if (!$this->knows($entity)) {
            $this->entities[] = $entity;
        }
        if ($entity->getId() && !array_key_exists($entity->getId(), $this->keyMap)) {
            $this->keyMap[$entity->getId()] = array_search($entity, $this->entities);
        }

        if ($entity->getId()) {
            $this->original[$entity->getId()] = $entity->toArray();
        }

        return $entity;
    }

    private function generateId(Contracts\Entity $entity)
    {
        $manager = $this->type->getManager();
        $name = $this->type->getName();
        $spaceId = $this->type->getSpaceId();

        $sequence = $manager->get('sequence')->oneBySpace($spaceId);
        if (!$sequence) {
            $sequence = $manager->get('sequence')->create([
                'space' => $spaceId,
                'value' => 0,
            ]);
            $manager->save($sequence);
        }

        $nextValue = +$manager->getMeta()
            ->get('sequence')
            ->getSpace()
            ->update($sequence->id, [['+', 2, 1]])
            ->getData()[0][2];

        $entity->setId($nextValue);

        $this->register($entity);

        return $entity;
    }

    public function getType()
    {
        return $this->type;
    }
}
