<?php

namespace Repositories;

use Tarantool\Mapper\Repository;

class PostRepository extends Repository
{
    public $indexes = [
        ['id'],
        ['slug']
    ];
}
