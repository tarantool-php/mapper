<?php

namespace Tarantool\Mapper;

interface Migration
{
    public function migrate(Mapper $mapper);
}