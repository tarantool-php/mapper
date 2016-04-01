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

        $schema->makeSpace('sequence');
        $schema->makeIndex('sequence', 'id', ['parts' => [1, 'NUM']]);
        $schema->makeIndex('sequence', 'space', ['parts' => [2, 'NUM']]);

        $schema->makeSpace('mapping');
        $schema->makeIndex('mapping', 'id', ['parts' => [1, 'NUM']]);
        $schema->makeIndex('mapping', 'space', ['parts' => [2, 'NUM'], 'unique' => false]);
        $schema->makeIndex('mapping', 'space_property', ['parts' => [2, 'NUM', 3, 'NUM']]);

        $client = $manager->getClient();

        $sequenceSpaceId = $schema->getSpaceId('sequence');
        $mappingSpaceId = $schema->getSpaceId('mapping');

        $mapping = $client->getSpace('mapping');
        $mapping->insert([1, $sequenceSpaceId, 0, 'id']);
        $mapping->insert([2, $sequenceSpaceId, 1, 'space']);
        $mapping->insert([3, $sequenceSpaceId, 2, 'value']);
        $mapping->insert([4, $mappingSpaceId, 0, 'id']);
        $mapping->insert([5, $mappingSpaceId, 1, 'space']);
        $mapping->insert([6, $mappingSpaceId, 2, 'line']);
        $mapping->insert([7, $mappingSpaceId, 3, 'property']);

        $sequence = $client->getSpace('sequence');
        $sequence->insert([1, $sequenceSpaceId, 2]);
        $sequence->insert([2, $mappingSpaceId, 7]);
    }
}
