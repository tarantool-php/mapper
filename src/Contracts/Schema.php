<?php

namespace Tarantool\Mapper\Contracts;

interface Schema
{
    public function hasSpace($space);
    public function createSpace($space);

    public function getSpaceId($name);
    public function getSpaceName($spaceId);

    public function createIndex($space, $index, array $arguments);
    public function listIndexes($space);
    public function dropIndex($spaceId, $index);
}
