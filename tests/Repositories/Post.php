<?php

namespace Repositories;

use Tarantool\Mapper\Repository;

class Post extends Repository
{
    public $indexes = [
        ['id'],
        ['slug']
    ];
}
