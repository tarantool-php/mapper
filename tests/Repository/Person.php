<?php

namespace Repository;

use Tarantool\Mapper\Repository as MapperRepository;

class Person extends MapperRepository
{
    public $indexes = [
        ['id'],
    ];
}
