<?php

namespace Tarantool\Mapper\Migrations;

use Tarantool\Mapper\Contracts;
use InvalidArgumentException;
use ReflectionClass;

class Migrator implements Contracts\Migration
{
    protected $migrations = [];

    public function registerMigration($class)
    {
        $reflection = new ReflectionClass($class);
        if (!$reflection->implementsInterface(Contracts\Migration::class)) {
            throw new InvalidArgumentException("Register only Migration classes");
        }
        $this->migrations = [];
    }

    public function migrate(Contracts\Manager $manager)
    {
        if (!$manager->getSchema()->hasSpace('migration')) {
            $instance = new Tracker();
            $instance->migrate($manager);
            $manager->save($manager->get('migration')->make(['name' => Migration::class]));
        }

        $repository = $manager->get('migration');

        foreach ($this->migrations as $migration) {
            $row = [
                'name' => $migration
            ];
            if (!$repository->find($row, true)) {
                $instance = new $migration;
                $instance->migrate($this->manager);
                $manager->save($repository->make($row));
            }
        }
    }
}
