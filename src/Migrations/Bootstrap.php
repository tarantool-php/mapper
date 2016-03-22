<?php

namespace Tarantool\Mapper\Migrations;

use Tarantool\Mapper\Contracts;

class Bootstrap implements Contracts\Migration
{
    public function migrate(Contracts\Manager $manager)
    {
        $client = $manager->getClient();
        if ($manager->getSchema()->hasSpace('sequence')) {
            return true;
        }

        $schema = $manager->getSchema();

        $schema->createSpace('sequence');
        $schema->createIndex('sequence', 'id', ['parts' => [1, 'NUM']]);
        $schema->createIndex('sequence', 'name', ['parts' => [2, 'STR']]);

        $schema->createSpace('mapping');
        $schema->createIndex('mapping', 'id', ['parts' => [1, 'NUM']]);
        $schema->createIndex('mapping', 'space', ['parts' => [2, 'STR'], 'unique' => false]);

        $client = $manager->getClient();

        $mapping = $client->getSpace('mapping');
        $mapping->insert([1, 'sequence', 0, 'id']);
        $mapping->insert([2, 'sequence', 1, 'name']);
        $mapping->insert([3, 'sequence', 2, 'value']);
        $mapping->insert([4, 'mapping', 0, 'id']);
        $mapping->insert([5, 'mapping', 1, 'space']);
        $mapping->insert([6, 'mapping', 2, 'line']);
        $mapping->insert([7, 'mapping', 3, 'property']);

        $sequence = $client->getSpace('sequence');
        $sequence->insert([1, 'sequence', 2]);
        $sequence->insert([2, 'mapping', 7]);
    }
}
