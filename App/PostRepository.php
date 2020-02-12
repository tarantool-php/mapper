<?php

use Tarantool\Mapper\Repository;

class PostRepository extends Repository
{
    public $engine = 'memtx'; // or vinyl

    public $indexes = [
        ['id'],
        [
            'fields' => ['id'],
            'unique' => true,
        ],
    ];
}
