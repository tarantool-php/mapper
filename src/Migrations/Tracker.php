<?php

namespace Tarantool\Mapper\Migrations;

use Tarantool\Mapper\Contracts;

class Tracker implements Contracts\Migration
{
    public function migrate(Contracts\Manager $manager)
    {
        if (!$manager->getSchema()->hasSpace('mapping')) {
            $migration = new Bootstrap();
            $migration->migrate($manager);
        }

        $migration = $manager->getMetadata()->create('migration', ['name']);
        $migration->addIndex(['name']);
        $manager->save($manager->get('migration')->make(['name' => Bootstrap::class]));
    }
}
