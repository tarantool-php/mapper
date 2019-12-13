<?php

declare(strict_types=1);

namespace Tarantool\Mapper;

class Bootstrap
{
    private $mapper;

    private $migrations = [];

    public function __construct(Mapper $mapper)
    {
        $this->mapper = $mapper;
    }

    public function register($instance) : self
    {
        foreach ($this->migrations as $candidate) {
            if ($candidate == $instance) {
                return $this;
            }
        }
        $this->migrations[] = $instance;
        return $this;
    }

    public function migrate() : Mapper
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

        return $this->mapper;
    }
}
