<?php

namespace Tarantool\Mapper\Contracts;

interface Meta
{
    /**
     * @return Type
     */
    public function get($type);

    /**
     * @return Type
     */
    public function make($type, array $fields = null);
}
