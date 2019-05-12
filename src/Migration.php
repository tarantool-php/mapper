<?php

declare(strict_types=1);

namespace Tarantool\Mapper;

interface Migration
{
    public function migrate(Mapper $mapper);
}
