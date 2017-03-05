<?php

use Tarantool\Mapper\Client;
use Tarantool\Mapper\Mapper;
use Tarantool\Client\Connection\StreamConnection;
use Tarantool\Client\Packer\PurePacker;
use Tarantool\Client\Schema\Space;
use Tarantool\Client\Schema\Index;

abstract class TestCase extends PHPUnit\Framework\TestCase
{
    protected function createMapper()
    {
    	return new Mapper($this->createClient());
    }

    protected function createClient()
    {
        $port = getenv('TNT_CONN_PORT') ?: 3301;
        $connection = new StreamConnection('tcp://'.getenv('TNT_CONN_HOST').':'.$port);
        return new Client($connection, new PurePacker());
    }

    protected function clean(Client $client)
    {
        $client->evaluate('
            local i, space, j, index
            for i, space in box.space._vspace:pairs() do
                if string.sub(space[3], 1, 1) ~= "_" then
                    box.space[space[3]]:drop()
                end
            end
        ');
    }
}