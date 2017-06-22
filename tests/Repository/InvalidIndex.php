<?php

namespace Repository;

use Tarantool\Mapper\Repository as MapperRepository;

class InvalidIndex extends MapperRepository
{
    public $indexes = [
        ['id'],
        ['name']
    ];
}
