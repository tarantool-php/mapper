<?php

namespace Repository;

use Tarantool\Mapper\Repository as MapperRepository;

class PostRepository extends MapperRepository
{
    public $indexes = [
        ['id'],
        ['slug']
    ];
}
