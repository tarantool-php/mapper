<?php

namespace Tarantool\Mapper\Migrations;

use Tarantool\Mapper\Contracts;
use InvalidArgumentException;

class Collection implements Contracts\Migration
{
    protected $migrations = [];

    public function registerMigration($class)
    {
        if (!is_subclass_of($class, Contracts\Migration::class)) {
            throw new InvalidArgumentException("Register only Migration classes");
        }
        $this->migrations = [];
    }

    public function migrate(Contracts\Manager $manager)
    {
        if(!$manager->getSchema()->hasSpace('migration')) {
            $instance = new Tracker();
            $instance->migrate($manager);
            $manager->save($manager->get('migration')->make(['name' => Migration::class]));
        }

        $repository = $manager->get('migration');

        foreach ($this->migrations as $migration) {
            if (!$repository->byName($migration)) {
                $instance = new $migration;
                $instance->migrate($this->manager);
                $manager->save($repository->create(['name' => $migration]));
            }
        }
    }
}
