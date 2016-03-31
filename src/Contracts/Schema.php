<?php

namespace Tarantool\Mapper\Contracts;

interface Schema
{
    public function hasSpace($space);
    public function makeSpace($space);

    public function makeIndex($space, $index, array $arguments);
    public function listIndexes($space);
}
