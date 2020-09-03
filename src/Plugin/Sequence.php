<?php

declare(strict_types=1);

namespace Tarantool\Mapper\Plugin;

use Exception;
use Tarantool\Client\Schema\Operations;
use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Plugin;
use Tarantool\Mapper\Procedure\CreateSequence;
use Tarantool\Mapper\Space;

class Sequence extends Plugin
{
    public function generateKey(Entity $instance, Space $space): Entity
    {
        $key = $space->getPrimaryKey();
        if ($key) {
            if (!property_exists($instance, $key) || $instance->$key === null) {
                $instance->$key = $this->generateValue($space);
            }
        }

        return $instance;
    }

    private $sequences = [];

    public function initializeSequence(Space $space)
    {
        if (!count($this->sequences)) {
            foreach ($this->mapper->find('_vsequence') as $sq) {
                $this->sequences[$sq->name] = true;
            }
        }

        $name = $space->getName();

        if (!array_key_exists($name, $this->sequences)) {
            [$primaryIndex] = $space->getIndexes();
            if (count($primaryIndex['parts']) !== 1) {
                throw new Exception("Composite primary key");
            }
            $this->mapper
                ->getPlugin(Procedure::class)
                ->get(CreateSequence::class)
                ->execute($name, $primaryIndex['name'], $primaryIndex['parts'][0][0] + 1);

            $this->mapper->getRepository('_vsequence')->flushCache();

            $this->sequences[$name] = true;
        }
    }

    private function generateValue(Space $space): int
    {
        $this->initializeSequence($space);

        $next = $this->mapper->getClient()
            ->call('box.sequence.' . $space->getName() . ':next');

        return $next[0];
    }
}
