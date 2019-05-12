<?php

declare(strict_types=1);

namespace Tarantool\Mapper\Plugin;

use Exception;
use Tarantool\Client\Schema\Operations;
use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Plugin;
use Tarantool\Mapper\Space;

class Sequence extends Plugin
{
    public function generateKey(Entity $instance, Space $space) : Entity
    {
        $primary = $space->getPrimaryIndex();
        if (count($primary['parts']) == 1) {
            $key = $space->getFormat()[$primary['parts'][0][0]]['name'];
            if (!property_exists($instance, $key) || is_null($instance->$key)) {
                $instance->$key = $this->generateValue($space);
            }
        }

        return $instance;
    }

    public function initSchema() : self
    {
        if (!$this->mapper->getSchema()->hasSpace('sequence')) {
            $sequence = $this->mapper->getSchema()->createSpace('sequence');
            $sequence->addProperty('space', 'unsigned');
            $sequence->addProperty('counter', 'unsigned');
            $sequence->createIndex('space');
        }

        return $this;
    }

    public function initializeSequence($space) : Entity
    {
        $this->initSchema();

        $spaceId = $space->getId();
        $instance = $this->mapper->findOne('sequence', $space->getId());
        if (!$instance) {
            $primaryIndex = $space->getIndexes()[0];
            if (count($primaryIndex['parts']) !== 1) {
                throw new Exception("Composite primary key");
            }
            $indexName = $primaryIndex['name'];
            $query = "box.space.".$space->getName().".index.$indexName:max";
            $data = $this->mapper->getClient()->call($query);
            $max = $data ? $data[0][$primaryIndex['parts'][0][0]] : 0;

            $instance = $this->mapper->create('sequence', [
                'space' => $space->getId(),
                'counter' => $max,
            ]);
        }

        return $instance;
    }

    private function generateValue(Space $space) : int
    {
        $instance = $this->initializeSequence($space);
        $repository = $this->mapper->getRepository('sequence');

        $field = $repository
            ->getSpace()
            ->getPropertyIndex('counter');

        $this->mapper->getClient()
            ->getSpace('sequence')
            ->update([$instance->space], Operations::add($field, 1));
        $repository->sync($instance->space);

        return $instance->counter;
    }
}
