<?php

namespace Repository;

use Tarantool\Mapper\Repository as MapperRepository;

class Posts extends MapperRepository
{
    public $indexes = [
        ['id'],
        ['slug']
    ];
}
