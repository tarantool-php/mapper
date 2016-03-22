<?php

namespace Tarantool\Mapper;

use Tarantool\Mapper\Contracts;
use BadMethodCallException;
use LogicException;

class Repository implements Contracts\Repository
{
    protected $type;
    protected $entities = [];
    protected $byId = [];

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
        $finder = null;
        $fields = null;
        $first = false;

        if(substr($method, 0, 2) == 'by') {
            $fields = substr($method, 2);
        }

        if(substr($method, 0, 7) == 'firstBy') {
            $first = true;
            $fields = substr($method, 7);
        }

        if(substr($method, 0, 5) == 'oneBy') {
            $first = true;
            $fields = substr($method, 5);
        }

        if($fields) {
            $fields = explode('_', snake_case($fields));
            return $this->find(array_combine($fields, $arguments), $first);
        }
        throw new BadMethodCallException("Method $method not found");
    }

    public function find($params, $first = false)
    {
        $fields = array_keys($params);
        sort($fields);
        $index = implode('_', $fields);

        $space = $this->type->getManager()->getClient()->getSpace($this->type->getName());
        $data = $space->select(array_values($params), $index);

        $result = [];
        if(!empty($data->getData())) {
            foreach($data->getData() as $tuple) {
                $data = $this->type->decode($tuple);
                if (isset($data['id']) && array_key_exists($data['id'], $this->byId)) {
                    $entity = $this->entities[$this->byId[$data['id']]];
                    $entity->update($data);

                } else {
                    $entity = new Entity($data);
                    $this->register($entity);
                }
                if($first) {
                    return $entity;
                }
                $result[] = $entity;
            }
        }
        if(!$first) {
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
        if(!$this->knows($entity)) {
            throw new LogicException("Entity is not related with this repository");
        }

        $manager = $this->type->getManager();
        $client = $manager->getClient();

        if(!$entity->getId()) {

            // generate id
            $sequence = $manager->get('sequence');
            $sequenceRow = $sequence->oneByName($this->type->getName());
            if(!$sequenceRow) {
                $sequenceRow = $sequence->make([
                    'name' => $this->type->getName(),
                    'value' => 1,
                ]);
                $manager->save($sequenceRow);
            }

            $response = $client->getSpace('sequence')->update($sequenceRow->id, [
                ['+', 2, 1]
            ]);

            $entity->setId($response->getData()[0][2]);

            $tuple = $this->type->encode($entity->toArray());
            $client->getSpace($this->type->getName())->insert($tuple);

            $this->register($entity);
        } else {
            $changes = $entity->pullChanges();
            if(count($changes)) {
                $operations = [];
                foreach($this->type->encode($changes) as $key => $value) {
                    $operations[] = ['=', $key +1, $value];
                }
                $client->getSpace($this->type->getName())->update($entity->getId(), $operations);
            }
        }
        $entity->pullChanges();
    }

    protected function register(Contracts\Entity $entity)
    {
        if(!$this->knows($entity)) {
            $this->entities[] = $entity;
        }
        if ($entity->getId() && !array_key_exists($entity->getId(), $this->byId)) {
            $this->byId[$entity->getId()] = array_search($entity, $this->entities);
        }
        return $entity;
    }
}
