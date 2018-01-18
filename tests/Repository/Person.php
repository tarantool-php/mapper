<?php

namespace Repository;

use Tarantool\Mapper\Repository as MapperRepository;

class Person extends MapperRepository
{
    public $engine = 'memtx';

    public $indexes = [
        ['id'],
        ['fullName'],
    ];
}
