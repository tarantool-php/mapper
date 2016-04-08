<?php

namespace Tarantool\Mapper;

use BadMethodCallException;
use LogicException;

class Repository implements Contracts\Repository
{
    private $type;
    private $entities = [];
    private $keyMap = [];
    private $findCache = [];

    private $magicMethodRules = [
        'by' => false,
        'firstBy' => true,
        'oneBy' => true,
    ];

    public function __construct(Contracts\Type $type)
    {
        $this->type = $type;
    }

    public function create($data = null)
    {
        if ($data && !is_array($data)) {
            $properties = $this->getType()->getProperties();
            if (count($properties) == 2) {
                $data = [$properties[1] => $data];
            } else {
                throw new LogicException('Data should be array');
            }
        }
        if ($data) {
            $newData = [];
            foreach ($data as $k => $v) {
                if (!is_numeric($k)) {
                    $newData[$k] = $v;
                } else {
                    if ($v instanceof Contracts\Entity) {
                        $type = $this->type->getManager()->findRepository($v)->getType();
                        $newData[$this->type->getReferenceProperty($type)] = $v;
                    }
                }
            }
            $data = $newData;
        }

        foreach ($data as $k => $v) {
            if (!$this->type->hasProperty($k)) {
                throw new \Exception("Unknown property $k");
            }
        }

        return $this->register(new Entity($data));
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
        $findKey = md5(json_encode(func_get_args()));
        if (array_key_exists($findKey, $this->findCache)) {
            return $this->findCache[$findKey];
        }
        if (is_string($params)) {
            if (1 * $params == $params) {
                $params = 1 * $params;
            }
        }
        if (is_int($params)) {
            if (isset($this->keyMap[$params])) {
                return $this->entities[$this->keyMap[$params]];
            }
            $params = [
                'id' => $params,
            ];
            $oneItem = true;
        }

        foreach ($params as $key => $value) {
            if ($this->type->isReference($key)) {
                if ($value instanceof Contracts\Entity) {
                    $params[$key] = $value->getId();
                } else {
                    $params[$key] = +$value;
                }
            }
        }

        $fields = array_keys($params);
        $values = [];

        sort($fields);
        foreach ($fields as $field) {
            $values[] = $params[$field];
        }

        $index = implode('_', $fields);

        if (!$index) {
            $index = 'id';
        }

        $space = $this->type->getSpace();
        $data = $space->select($values, $index);

        $result = [];
        if (!empty($data->getData())) {
            foreach ($data->getData() as $tuple) {
                $data = $this->type->decode($tuple);
                if (isset($data['id']) && array_key_exists($data['id'], $this->keyMap)) {
                    $entity = $this->entities[$this->keyMap[$data['id']]];
                    $entity->update($data);
                } else {
                    $entity = new Entity($data);
                    $this->register($entity);
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

        $this->type->getSpace()->delete([$entity->id]);
    }

    public function save(Contracts\Entity $entity)
    {
        if (!$this->knows($entity)) {
            throw new LogicException('Entity is not related with this repository');
        }

        if (!$entity->getId()) {
            $this->generateId($entity);
            $tuple = $this->type->encode($entity->toArray());

            $required = $this->type->getRequiredProperties();

            foreach ($this->type->getProperties() as $index => $field) {
                if (in_array($field, $required) && !array_key_exists($index, $tuple)) {
                    if ($this->type->isReference($field)) {
                        $tuple[$index] = 0;
                    } else {
                        $tuple[$index] = '';
                    }
                }
            }

            // normalize tuple
            if (array_values($tuple) != $tuple) {
                // index was skipped
                $max = max(array_keys($tuple));
                foreach (range(0, $max) as $index) {
                    if (!array_key_exists($index, $tuple)) {
                        $tuple[$index] = null;
                    }
                }
                ksort($tuple);
            }

            $this->type->getSpace()->insert($tuple);
        } else {
            $changes = $entity->pullChanges();
            if (count($changes)) {
                $operations = [];
                foreach ($this->type->encode($changes) as $key => $value) {
                    $operations[] = ['=', $key, $value];
                }

                $this->type->getSpace()->update($entity->getId(), $operations);
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
