<?php

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

    public function getName()
    {
        $class = get_class($this);
        $name = str_replace("Procedure\\", '', $class);
        return strtolower(implode('_', explode('\\', $name)));
    }

    public function __invoke()
    {
        return $this->plugin->invoke($this, func_get_args());
    }
}
