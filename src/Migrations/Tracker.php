<?php

namespace Tarantool\Mapper\Migrations;

use Tarantool\Mapper\Contracts;

class Tracker implements Contracts\Migration
{
    public function migrate(Contracts\Manager $manager)
    {
        if (!$manager->getSchema()->hasSpace('meta')) {
            $migration = new Meta();
            $migration->migrate($manager);
        }

        $migration = $manager->getMeta()->create('migrations', ['name']);
        $migration->addIndex(['name']);
        $manager->save($manager->get('migrations')->make(['name' => Bootstrap::class]));
        $manager->save($manager->get('migrations')->make(['name' => Meta::class]));
    }
}
