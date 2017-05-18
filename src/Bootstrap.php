<?php

namespace Tarantool\Mapper;

class Bootstrap
{
    private $mapper;
    private $migrations = [];

    public function __construct(Mapper $mapper)
    {
        $this->mapper = $mapper;
    }

    public function register($instance)
    {
        $this->migrations[] = $instance;
    }

    public function migrate()
    {
        $schema = $this->mapper->getSchema();
        foreach ($this->migrations as $migration) {
            if (!is_object($migration)) {
                $migration = new $migration;
            }
            $schema->once(get_class($migration), function () use ($migration) {
                $migration->migrate($this->mapper);
            });
        }
    }
}
