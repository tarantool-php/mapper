<?php

declare(strict_types=1);

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
