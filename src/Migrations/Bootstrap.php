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
        $schema->createIndex('sequence', 'space', ['parts' => [2, 'NUM']]);

        $schema->createSpace('mapping');
        $schema->createIndex('mapping', 'id', ['parts' => [1, 'NUM']]);
        $schema->createIndex('mapping', 'space', ['parts' => [2, 'NUM'], 'unique' => false]);
        $schema->createIndex('mapping', 'line_space', ['parts' => [3, 'NUM', 2, 'NUM']]);
        $schema->createIndex('mapping', 'type', ['parts' => [5, 'STR'], 'unique' => false]);

        $client = $manager->getClient();

        $sequenceSpaceId = $schema->getSpaceId('sequence');
        $mappingSpaceId = $schema->getSpaceId('mapping');

        $mapping = $client->getSpace('mapping');
        $mapping->insert([1, $sequenceSpaceId, 0, 'id', 'integer']);
        $mapping->insert([2, $sequenceSpaceId, 1, 'space', 'integer']);
        $mapping->insert([3, $sequenceSpaceId, 2, 'value', 'integer']);
        $mapping->insert([4, $mappingSpaceId, 0, 'id', 'integer']);
        $mapping->insert([5, $mappingSpaceId, 1, 'space', 'integer']);
        $mapping->insert([6, $mappingSpaceId, 2, 'line', 'integer']);
        $mapping->insert([7, $mappingSpaceId, 3, 'property', 'string']);
        $mapping->insert([8, $mappingSpaceId, 4, 'type', 'string']);

        $sequence = $client->getSpace('sequence');
        $sequence->insert([1, $sequenceSpaceId, 2]);
        $sequence->insert([2, $mappingSpaceId, 8]);
    }
}
