<?php

namespace Tarantool\Mapper\Plugin;

use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Plugin;
use Tarantool\Mapper\Space;

class Sequence extends Plugin
{
    public function generateKey(Entity $instance, Space $space)
    {
        $primary = $space->getPrimaryIndex();
        if (count($primary['parts']) == 1) {
            $key = $space->getFormat()[$primary['parts'][0][0]]['name'];
            if (!property_exists($instance, $key) || is_null($instance->$key)) {
                $instance->$key = $this->generateValue($space);
            }
        }
    }

    public function initSchema()
    {
        if (!$this->mapper->getSchema()->hasSpace('sequence')) {
            $sequence = $this->mapper->getSchema()->createSpace('sequence');
            $sequence->addProperty('space', 'unsigned');
            $sequence->addProperty('counter', 'unsigned');
            $sequence->createIndex('space');
        }
    }

    public function initializeSequence($space)
    {
        $this->initSchema();

        $spaceId = $space->getId();
        $entity = $this->mapper->findOne('sequence', $space->getId());
        if (!$entity) {
            $query = "return box.space.".$space->getName().".index[0]:max()";
            $data = $this->mapper->getClient()->evaluate($query)->getData();
            $max = $data ? $data[0][0] : 0;

            $entity = $this->mapper->create('sequence', [
                'space' => $space->getId(),
                'counter' => $max,
            ]);
        }

        return $entity;
    }

    private function generateValue($space)
    {
        $entity = $this->initializeSequence($space);

        $this->mapper->getSchema()->getSpace('sequence')
            ->getRepository()
            ->update($entity, [['+', 'counter', 1]]);

        return $entity->counter;
    }
}
