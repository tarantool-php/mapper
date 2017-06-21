<?php

namespace Tarantool\Mapper;

use Exception;
use Tarantool\Client\Connection\Connection;
use Tarantool\Client\Client as TarantoolClient;
use Tarantool\Client\Request\Request;

class Client extends TarantoolClient
{
    private $logging = false;
    private $log = [];

    private $disabledRequests = [];

    public function connect()
    {
        $start = microtime(1);
        $result = parent::connect();
        $this->log($start, Connection::class);
        return $result;
    }

    public function disableRequest($class)
    {
        if (!is_subclass_of($class, Request::class)) {
            throw new Exception("Invalid request type: " . $class);
        }
        $this->disabledRequests[] = $class;
    }

    public function resetDisabled()
    {
        $this->disabledRequests = [];
    }

    public function sendRequest(Request $request)
    {
        if (in_array(get_class($request), $this->disabledRequests)) {
            throw new Exception(get_class($request) . ' is disabled');
        }
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

    public function getTimeSummary()
    {
        $summary = 0;
        foreach ($this->log as $request) {
            $summary += $request[0];
        }
        return $summary;
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
