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

        $schema->createSpace('property');
        $schema->createIndex('property', 'id', ['parts' => [1, 'NUM']]);
        $schema->createIndex('property', 'space', ['parts' => [2, 'NUM'], 'unique' => false]);
        $schema->createIndex('property', 'line_space', ['parts' => [3, 'NUM', 2, 'NUM']]);
        $schema->createIndex('property', 'type', ['parts' => [5, 'STR'], 'unique' => false]);

        $client = $manager->getClient();

        $sequenceSpaceId = $schema->getSpaceId('sequence');
        $propertySpaceId = $schema->getSpaceId('property');

        $property = $client->getSpace('property');
        $property->insert([1, $sequenceSpaceId, 0, 'id', 'integer']);
        $property->insert([2, $sequenceSpaceId, 1, 'space', 'integer']);
        $property->insert([3, $sequenceSpaceId, 2, 'value', 'integer']);
        $property->insert([4, $propertySpaceId, 0, 'id', 'integer']);
        $property->insert([5, $propertySpaceId, 1, 'space', 'integer']);
        $property->insert([6, $propertySpaceId, 2, 'line', 'integer']);
        $property->insert([7, $propertySpaceId, 3, 'property', 'string']);
        $property->insert([8, $propertySpaceId, 4, 'type', 'string']);

        $sequence = $client->getSpace('sequence');
        $sequence->insert([1, $sequenceSpaceId, 2]);
        $sequence->insert([2, $propertySpaceId, 8]);
    }
}
