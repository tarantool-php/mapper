<?php

namespace Tarantool\Mapper\Plugins;

use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Plugin;
use Tarantool\Mapper\Space;

class Sequence extends Plugin
{
    public function beforeCreate(Entity $instance, Space $space)
    {
        $primary = $space->getPrimaryIndex();
        if(count($primary->parts) == 1) {
            $key = $space->getFormat()[$primary->parts[0][0]]['name'];
            if(!property_exists($instance, $key)) {
                $instance->$key = $this->generateValue($space);
            }
        }
    }

    private function generateValue($space)
    {
        $spaceId = $space->getId();

        if(!$this->mapper->getSchema()->hasSpace('sequence')) {

            $sequence = $this->mapper->getSchema()->createSpace('sequence');
            $sequence->addProperty('space', 'unsigned');
            $sequence->addProperty('counter', 'unsigned');

            $sequence->createIndex('space');

            $this->mapper->create('sequence', [
                'space' => $sequence->getId(),
                'counter' => 1
            ]);
        }

        $entity = $this->mapper->findOne('sequence', $space->getId());
        if(!$entity) {
            $entity = $this->mapper->create('sequence', [
                'space' => $space->getId(),
                'counter' => 0,
            ]);
        }

        $this->mapper->getSchema()->getSpace('sequence')
            ->getRepository()
            ->update($entity, [['+', 'counter', 1]]);

        return $entity->counter;
    }
}
