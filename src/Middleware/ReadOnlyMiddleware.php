<?php

declare(strict_types=1);

namespace Tarantool\Mapper\Middleware;

use Tarantool\Client\Request\AuthenticateRequest;
use Tarantool\Client\Request\PingRequest;
use Tarantool\Client\Request\SelectRequest;

final class ReadOnlyMiddleware extends RequestFilterMiddleware
{
    public function __construct()
    {
        $enabled = [
            AuthenticateRequest::class,
            PingRequest::class,
            SelectRequest::class,
        ];

        foreach ($enabled as $classname) {
            $this->addWhitelist($classname);
        }
    }
}