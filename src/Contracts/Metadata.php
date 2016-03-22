<?php

namespace Tarantool\Mapper\Contracts;

interface Metadata
{
    /**
     * @return Type
     */
    public function get($type);

    /**
     * @return Type
     */
    public function create($type, array $fields = null);
}
