<?php

namespace Tarantool\Mapper;

use Exception;

class Pool
{
    private $description = [];
    private $mappers = [];

    public function register($name, $handler)
    {
        if (array_key_exists($name, $this->description)) {
            throw new Exception("Mapper $name was registered");
        }

        if ($handler instanceof Mapper) {
            $this->description[$name] = $handler;
            $this->mappers[$name] = $handler;
            return;
        }

        if (!is_callable($handler)) {
            throw new Exception("Invalid $name handler");
        }

        $this->description[$name] = $handler;
    }

    public function get($name)
    {
        if (array_key_exists($name, $this->mappers)) {
            return $this->mappers[$name];
        }

        if (!array_key_exists($name, $this->description)) {
            throw new Exception("Mapper $name was not registered");
        }

        return $this->mappers[$name] = call_user_func($this->description[$name]);
    }
}
