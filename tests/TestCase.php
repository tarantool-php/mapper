<?php

use Tarantool\Client\Client;
use Tarantool\Mapper\Mapper;
use Tarantool\Client\Packer\PurePacker;
use Tarantool\Client\Schema\Space;
use Tarantool\Client\Schema\Index;

abstract class TestCase extends PHPUnit\Framework\TestCase
{
    protected function createMapper()
    {
        $client = $this->createClient();
        $client->evaluate("box.session.su('admin')");
        return new Mapper($client);
    }

    protected function createClient()
    {
        $host = getenv('TNT_CONN_HOST');
        $port = getenv('TNT_CONN_PORT') ?: 3301;
        return Client::fromDsn("tcp://$host:$port");
    }

    protected function clean(Mapper $mapper)
    {
        foreach ($mapper->find('_schema') as $schema) {
            if (strpos($schema->key, 'mapper-once') === 0) {
                $mapper->remove($schema);
            }
        }

        $mapper->getClient()->flushSpaces();
        $mapper->getRepository('_space')->flushCache();

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
