<?php

declare(strict_types=1);

namespace Tarantool\Mapper\Tests;

use Tarantool\Mapper\Space;

class TypedProperties
{
    public int $id;
    public string $name;
    public string $nick = 'nick';

    public static function initSchema(Space $space)
    {
        $space->addIndex(['name'], ['unique' => false]);
    }
}
