<?php

namespace Tarantool\Mapper\Tests;

use Psr\Log\AbstractLogger;

class Logger extends AbstractLogger
{
    protected $log = [];

    public function log($level, $message, array $context = [])
    {
        if (!array_key_exists('duration_ms', $context)) {
            // record only complete requests
            return ;
        }
        $this->log[] = compact('level', 'message', 'context');
    }

    public function getLog() : array
    {
        return $this->log;
    }

    public function flush() : self
    {
        $this->log = [];
        return $this;
    }
}
