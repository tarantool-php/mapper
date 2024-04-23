<?php

namespace Tarantool\Mapper;

class Change
{
    public function __construct(
        public string $type,
        public string $space,
        public array $data,
    ) {
    }
}
