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

    public function getMigrations()
    {
        return $this->migrations;
    }

    public function migrate(Contracts\Manager $manager)
    {
        if (!$manager->getSchema()->hasSpace('migrations')) {
            $instance = new Tracker();
            $instance->migrate($manager);
        }

        foreach ($this->migrations as $migration) {
            $name = is_object($migration) ? get_class($migration) : $migration;
            if (!$manager->get('migrations')->oneByName($name)) {
                $instance = is_object($migration) ? $migration : new $migration();
                $instance->migrate($manager);
                $manager->create('migrations', $name);
            }
        }
    }
}
