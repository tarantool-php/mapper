<?php

declare(strict_types=1);

namespace Tarantool\Mapper;

abstract class Migration
{
    public function afterSchema(Mapper $mapper): void
    {
    }

    public function beforeSchema(Mapper $mapper): void
    {
    }
}
