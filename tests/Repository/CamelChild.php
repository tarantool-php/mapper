<?php

namespace Repository;

use Tarantool\Mapper\Repository as MapperRepository;

class CamelChild extends MapperRepository
{
    public $indexes = [
        ['id'],
        [
            'fields' => 'camelParent',
            'unique' => false,
        ]
    ];
}
