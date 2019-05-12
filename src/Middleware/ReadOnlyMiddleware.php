<?php

declare(strict_types=1);

namespace Tarantool\Mapper\Middleware;

use Tarantool\Client\Request\Authenticate;
use Tarantool\Client\Request\Ping;
use Tarantool\Client\Request\Select;

final class ReadOnlyMiddleware extends RequestFilterMiddleware
{
    public function __construct()
    {
        $enabled = [
            Authenticate::class,
            Ping::class,
            Select::class,
        ];

        foreach ($enabled as $classname) {
            $this->addWhitelist($classname);
        }
    }
}