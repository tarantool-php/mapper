<?php

namespace Repository;

use Tarantool\Mapper\Repository as MapperRepository;

class CamelChild extends MapperRepository
{
    public $engine = 'vinyl';

    public $indexes = [
        ['id'],
        [
            'fields' => 'camelParent',
            'unique' => false,
        ]
    ];
}
