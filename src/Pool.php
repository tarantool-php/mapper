<?php

namespace Tarantool\Mapper;

use Exception;

class Pool
{
    private $description = [];
    private $mappers = [];
    private $resolvers = [];

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
        return $this;
    }

    public function registerResolver($resolver)
    {
        $this->resolvers[] = $resolver;
        return $this;
    }

    public function get($name)
    {
        if (array_key_exists($name, $this->mappers)) {
            return $this->mappers[$name];
        }

        if (array_key_exists($name, $this->description)) {
            return $this->mappers[$name] = call_user_func($this->description[$name]);
        }

        foreach ($this->resolvers as $resolver) {
            $mapper = call_user_func($resolver, $name);
            if ($mapper) {
                return $this->mappers[$name] = $mapper;
            }
        }

        throw new Exception("Mapper $name is not registered");
    }
}
