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

    protected function clean(Mapper $mapper)
    {
        $mapper->getClient()->evaluate('
            local todo = {}
            for i, space in box.space._space:pairs() do
                if space[1] >= 512 then
                    table.insert(todo, space[3])
                end
            end
            for i, name in pairs(todo) do
                box.space[name]:drop()
            end
        ');

        $mapper->getSchema()->reset();
        $mapper->getRepository('_space')->flushCache();

        foreach ($mapper->find('_schema') as $schema) {
            if (strpos($schema->key, 'mapper-once') === 0) {
                $mapper->remove($schema);
            }
        }
    }
}
