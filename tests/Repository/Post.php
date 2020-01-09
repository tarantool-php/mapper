<?php

namespace Repository;

use Tarantool\Mapper\Repository as MapperRepository;

class Post extends MapperRepository
{
    public $local = true;

    public $indexes = [
        ['id'],
        ['slug']
    ];
}
