<?php

namespace Tarantool\Mapper\Tests;

use Exception;
use Tarantool\Client\Client;
use Tarantool\Client\Packer\PurePacker;
use Tarantool\Client\Schema\Index;
use Tarantool\Client\Schema\Space;
use Tarantool\Mapper\Mapper;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected function createMapper()
    {
        $client = $this->createClient(...func_get_args());
        try {
            $client->evaluate("box.session.su('admin')");
        } catch (Exception $e) {
            // skip admin privileges failure
        }
        return new Mapper($client);
    }

    protected function createClient()
    {
        $host = getenv('TNT_CONN_HOST');
        $port = getenv('TNT_CONN_PORT') ?: 3301;
        $client = Client::fromDsn("tcp://$host:$port");
        foreach (func_get_args() as $middleware) {
            $client = $client->withMiddleware($middleware);
        }
        return $client;
    }

    protected function clean(Mapper $mapper)
    {
        foreach ($mapper->find('_schema') as $schema) {
            if (strpos($schema->key, 'mapper-once') === 0) {
                $mapper->getClient()->call('box.space._schema:delete', [$schema->key]);
            }
        }

        $mapper->getClient()->flushSpaces();

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
            for i, s in box.space._vsequence:pairs() do
                box.sequence[s.name]:drop()
            end
        ');

        $mapper->getSchema()->reset();
    }
}
