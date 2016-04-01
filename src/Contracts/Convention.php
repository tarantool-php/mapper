<?php

namespace Tarantool\Mapper\Contracts;

interface Convention
{
    public function isPrimitive($type);
    public function getType($property);
    public function getTarantoolType($type);

    public function encode($type, $value);
    public function decode($type, $value);
}
