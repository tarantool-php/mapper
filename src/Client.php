<?php

namespace Tarantool\Mapper;

use Tarantool\Client\Connection\Connection;
use Tarantool\Client\Client as TarantoolClient;
use Tarantool\Client\Request\Request;

class Client extends TarantoolClient
{
    private $logging = false;
    private $log = [];

    public function connect()
    {
        $start = microtime(1);
        $result = parent::connect();
        $this->log($start, Connection::class);
        return $result;
    }

    public function sendRequest(Request $request)
    {
        $start = microtime(1);
        $response = parent::sendRequest($request);
        $this->log($start, get_class($request), $request->getBody(), $response->getData());
        return $response;
    }

    private function log($start, $class, $request = [], $response = [])
    {
        if ($this->logging) {
            $this->log[] = [microtime(1) - $start, $class, $request, $response];
        }
    }

    public function setLogging($logging)
    {
        $this->logging = $logging;
    }

    public function getLog()
    {
        return $this->log;
    }

}