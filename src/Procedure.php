<?php

declare(strict_types=1);

namespace Tarantool\Mapper;

use Tarantool\Mapper\Plugin\Procedure as ProcedurePlugin;

abstract class Procedure
{
    private $plugin;

    public function __construct(ProcedurePlugin $plugin)
    {
        $this->plugin = $plugin;
    }

    abstract public function getBody() : string;
    abstract public function getParams() : array;

    public function getName() : string
    {
        $class = get_class($this);
        $name = str_replace("Procedure\\", '', $class);
        return strtolower(implode('_', explode('\\', $name)));
    }

    public function getMapping() : array
    {
        return [];
    }

    public function __invoke()
    {
        $raw = $this->plugin->invoke($this, func_get_args());
        if (!count($this->getMapping())) {
            return $raw;
        }

        $result = [];
        foreach ($this->getMapping() as $i => $name) {
            $result[$name] = $raw[$i];
        }
        return $result;
    }
}
