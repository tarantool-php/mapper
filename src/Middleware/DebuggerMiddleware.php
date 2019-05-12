<?php

declare(strict_types=1);

namespace Tarantool\Mapper\Middleware;

use Exception;
use LogicException;
use Tarantool\Client\Handler\Handler;
use Tarantool\Client\Request\Authenticate;
use Tarantool\Client\Request\Request;
use Tarantool\Client\Response;
use Tarantool\Client\Middleware\Middleware;

class DebuggerMiddleware implements Middleware
{
    private $log = [];

    public function process(Request $request, Handler $handler) : Response
    {
        $start = microtime(true);

        $response = $handler->handle($request);

        $this->log[] = [
            'request' => $request,
            'response' => $response,
            'timing' => microtime(true) - $start,
        ];

        return $response;
    }

    public function flush() : self
    {
        $this->log = [];
        return $this;
    }

    public function getLog() : array
    {
        return $this->log;
    }
}