<?php

declare(strict_types=1);

namespace Tarantool\Mapper\Tests;

use Tarantool\Client\Handler\Handler;
use Tarantool\Client\Middleware\Middleware as MiddlewareInterface;
use Tarantool\Client\Request\Request;
use Tarantool\Client\Response;

class Middleware implements MiddlewareInterface
{
    public array $data = [];

    public function process(Request $request, Handler $handler): Response
    {
        $response = $handler->handle($request);
        $this->data[] = [$request, $response];
        return $response;
    }
}
