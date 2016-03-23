<?php

namespace Tarantool\Mapper;

use Tarantool\Mapper\Contracts;
use BadMethodCallException;
use LogicException;

class Repository implements Contracts\Repository
{
    protected $type;
    protected $entities = [];
    protected $keyMap = [];

    protected $magicMethodRules = [
        'by' => false,
        'firstBy' => true,
        'oneBy' => true
    ];

    public function __construct(Contracts\Type $type)
    {
        $this->type = $type;
    }

    public function make(array $data = null)
    {
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

    public function find($params = [], $oneItem = false)
    {
        if(is_int($params)) {
            $params = [
                'id' => $params
            ];
            $oneItem = true;
        }

        $fields = array_keys($params);
        $values = [];

        sort($fields);
        foreach($fields as $field) {
            $values[] = $params[$field];
        }

        $index = implode('_', $fields);

        if(!$index) {
            $index = 'id';
        }

        $space = $this->type->getManager()->getClient()->getSpace($this->type->getName());
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
                    return $entity;
                }
                $result[] = $entity;
            }
        }
        if (!$oneItem) {
            return $result;
        }
    }

    /**
     * @return Entity
     */
    public function knows(Contracts\Entity $entity)
    {
        return in_array($entity, $this->entities);
    }

    public function save(Contracts\Entity $entity)
    {
        if (!$this->knows($entity)) {
            throw new LogicException("Entity is not related with this repository");
        }

        if (!$entity->getId()) {
            $this->generateId($entity);
            $tuple = $this->type->encode($entity->toArray());
            $this->type->getSpace()->insert($tuple);
        } else {
            $changes = $entity->pullChanges();
            if (count($changes)) {
                $operations = [];
                foreach ($this->type->encode($changes) as $key => $value) {
                    $operations[] = ['=', $key, $value];
                }

                $result = $this->type->getSpace()->update($entity->getId(), $operations);
            }
        }

        return $entity;
    }

    protected function register(Contracts\Entity $entity)
    {
        if (!$this->knows($entity)) {
            $this->entities[] = $entity;
        }
        if ($entity->getId() && !array_key_exists($entity->getId(), $this->keyMap)) {
            $this->keyMap[$entity->getId()] = array_search($entity, $this->entities);
        }
        return $entity;
    }

    protected function generateId(Contracts\Entity $entity)
    {
        $manager = $this->type->getManager();
        $name = $this->type->getName();

        $sequence = $manager->get('sequences')->oneByName($name);
        if (!$sequence) {
            $sequence = $manager->get('sequences')->make([
                'name' => $name,
                'value' => 0,
            ]);
            $manager->save($sequence);
        }

        $nextValue = $manager->getClient()
            ->getSpace('sequences')
            ->update($sequence->id, [['+', 2, 1]])
            ->getData()[0][2];

        $entity->setId($nextValue);

        $this->register($entity);

        return $entity;
    }
}
