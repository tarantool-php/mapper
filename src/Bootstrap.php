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
        foreach($this->migrations as $migration) {
            if(!is_object($migration)) {
                $migration = new $migration;
            }
            $migration->migrate($this->mapper);
        }
    }
}