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

        $migration = $manager->getMeta()->create('migrations', ['name']);
        $migration->addIndex(['name']);
        $manager->save($manager->get('migrations')->make(['name' => Bootstrap::class]));
    }
}
