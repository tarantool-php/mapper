<?php

namespace Repository;

use Tarantool\Mapper\Repository as MapperRepository;

class Persons extends MapperRepository
{
    public $indexes = [
        ['id'],
    ];
}
