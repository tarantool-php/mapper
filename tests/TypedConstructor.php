<?php

declare(strict_types=1);

namespace Tarantool\Mapper\Tests;

use Tarantool\Mapper\Space;

class TypedConstructor
{
    public function __construct(
        public readonly int $id,
        public string $name,
        public string $nick = 'nick',
        public array $configuration = [],
        public ?array $context = null,
    ) {
    }

    public static function getSpaceName(): string
    {
        return 'constructor';
    }

    public static function initSchema(Space $space)
    {
        $space->addIndex(['name'], ['unique' => false]);
    }
}
