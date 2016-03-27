<?php

namespace Tarantool\Mapper\Migrations;

use Tarantool\Mapper\Contracts;

class Bootstrap implements Contracts\Migration
{
    public function migrate(Contracts\Manager $manager)
    {
        $client = $manager->getClient();
        if ($manager->getSchema()->hasSpace('sequences')) {
            return true;
        }

        $schema = $manager->getSchema();

        $schema->makeSpace('sequences');
        $schema->makeIndex('sequences', 'id', ['parts' => [1, 'NUM']]);
        $schema->makeIndex('sequences', 'name', ['parts' => [2, 'STR']]);

        $schema->makeSpace('mapping');
        $schema->makeIndex('mapping', 'id', ['parts' => [1, 'NUM']]);
        $schema->makeIndex('mapping', 'space', ['parts' => [2, 'STR'], 'unique' => false]);

        $client = $manager->getClient();

        $mapping = $client->getSpace('mapping');
        $mapping->insert([1, 'sequences', 0, 'id']);
        $mapping->insert([2, 'sequences', 1, 'name']);
        $mapping->insert([3, 'sequences', 2, 'value']);
        $mapping->insert([4, 'mapping', 0, 'id']);
        $mapping->insert([5, 'mapping', 1, 'space']);
        $mapping->insert([6, 'mapping', 2, 'line']);
        $mapping->insert([7, 'mapping', 3, 'property']);

        $sequence = $client->getSpace('sequences');
        $sequence->insert([1, 'sequences', 2]);
        $sequence->insert([2, 'mapping', 7]);
    }
}
