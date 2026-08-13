<?php

declare(strict_types=1);

namespace Tarantool\Mapper\Tests;

use Tarantool\Mapper\Space;

class TypedProperties
{
    public int $id;
    public string $name;
    public string $nick = 'nick';
    public ?string $optional = null;

    public static function initSchema(Space $space)
    {
        // Add index on nullable field
        $space->addIndex(['optional'], ['unique' => false]);
        $space->addIndex(['name'], ['unique' => false]);
    }
}
