<?php

namespace Tarantool\Mapper\Contracts;

interface Migration
{
    public function migrate(Manager $manager);
}
