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
            throw new InvalidArgumentException('Register only Migration classes');
        }
        $this->migrations[] = $class;
    }

    public function migrate(Contracts\Manager $manager)
    {
        if (!$manager->getSchema()->hasSpace('migrations')) {
            $instance = new Tracker();
            $instance->migrate($manager);
            $manager->save($manager->get('migrations')->make(['name' => Migration::class]));
        }

        $repository = $manager->get('migrations');

        foreach ($this->migrations as $migration) {
            $row = [
                'name' => $migration,
            ];
            if (!$repository->find($row, true)) {
                $instance = new $migration();
                $instance->migrate($manager);
                $manager->save($repository->make($row));
            }
        }
    }
}
