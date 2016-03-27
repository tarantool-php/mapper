<?php

namespace Tarantool\Mapper\Migrations;

use Tarantool\Mapper\Contracts;

class Meta implements Contracts\Migration
{
    public function migrate(Contracts\Manager $manager)
    {
        if (!$manager->getSchema()->hasSpace('meta')) {
            $migration = new Bootstrap();
            $migration->migrate($manager);
        }

        $reference = $manager->getMeta()->create('reference', [
            'space', 'property', 'type',
        ])->addIndex(['space'], ['unique' => false]);
    }
}
