<?php

namespace Repository;

use Tarantool\Mapper\Repository as MapperRepository;

class Post extends MapperRepository
{
    public $indexes = [
        ['id'],
        ['slug']
    ];
}
