<?php

declare(strict_types=1);

namespace Tarantool\Mapper\Middleware;

use Exception;
use LogicException;
use Tarantool\Client\Handler\Handler;
use Tarantool\Client\Request\Request;
use Tarantool\Client\Response;
use Tarantool\Client\Middleware\Middleware;

class RequestFilterMiddleware implements Middleware
{
    private $whitelist = [];
    private $blacklist = [];

    public function addBlacklist(string $classname) : self
    {
        return $this->add('blacklist', $classname);
    }

    public function addWhitelist(string $classname) : self
    {
        return $this->add('whitelist', $classname);
    }

    public function process(Request $request, Handler $handler) : Response
    {
        $class = get_class($request);
        $isValid = !array_key_exists($class, $this->blacklist);
        if ($isValid) {
            $isValid = !count($this->whitelist) || array_key_exists($class, $this->whitelist);
        }

        if (!$isValid) {
            throw new Exception("Request $class is not allowed");
        }

        return $handler->handle($request);
    }

    private function add(string $category, string $classname) : self
    {
        if (!is_subclass_of($classname, Request::class)) {
            throw new LogicException("$classname should extend ".Request::class);
        }

        $this->$category[$classname] = true;
        return $this;
    }
}