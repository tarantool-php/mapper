<?php

namespace Tarantool\Mapper\Migrations;

use Tarantool\Mapper\Contracts;

class Tracker implements Contracts\Migration
{
    public function migrate(Contracts\Manager $manager)
    {
        if (!$manager->getSchema()->hasSpace('meta')) {
            $migration = new Bootstrap();
            $migration->migrate($manager);
        }

        $migration = $manager->getMeta()->make('migrations', ['name']);
        $migration->addIndex(['name']);

        $manager->make('migrations', ['name' => Bootstrap::class]);
        $manager->make('migrations', ['name' => self::class]);
    }
}
