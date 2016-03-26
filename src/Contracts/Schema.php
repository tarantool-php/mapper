<?php

namespace Tarantool\Mapper\Contracts;

interface Schema
{
    public function hasSpace($space);

    public function createSpace($space);

    public function createIndex($space, $index, array $arguments);
}
