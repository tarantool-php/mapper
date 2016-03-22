<?php

namespace Tarantool\Mapper\Contracts;

use Tarantool\Client;

interface Schema
{
    public function hasSpace($space);

    public function createSpace($space);

    public function createIndex($space, $index, array $arguments);
}
