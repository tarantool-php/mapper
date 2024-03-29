<?php

namespace Tarantool\Mapper\Tests;

use Psr\Log\AbstractLogger;
use Symfony\Component\Uid\Uuid;
use Tarantool\Client\Client;
use Tarantool\Client\Middleware\FirewallMiddleware;
use Tarantool\Client\Middleware\LoggingMiddleware;
use Tarantool\Client\Request\InsertRequest;
use Tarantool\Client\Schema\Operations;
use Tarantool\Mapper\Mapper;
use Tarantool\Mapper\Plugin\Procedure;
use Tarantool\Mapper\Plugin\Sequence;
use Tarantool\Mapper\Procedure\FindOrCreate;
use Tarantool\Mapper\Schema;

class UuidTest extends TestCase
{
    public function testPrimaryKey()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->getSchema()->createSpace('test_space', [
            'engine'        => 'memtx',
            'properties'    => [
                'id' => 'uuid',
            ],
        ])
        ->addIndex([ 'fields' => 'id' ]);

        $uuid = Uuid::v4();

        $instance = $mapper->create('test_space', [
            'id' => $uuid,
        ]);

        $this->assertNotNull($mapper->findOne('test_space', $uuid));
    }

    public function testBasics()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->getSchema()->createSpace('test_space', [
            'engine'        => 'memtx',
            'properties'    => [
                'id'          => 'unsigned',
                'uuid' => 'uuid',
            ],
        ])
        ->addIndex([ 'fields' => 'id' ])
        ->addIndex([ 'fields' => 'uuid' ]);

        $uuid = Uuid::v4();

        $instance = $mapper->create('test_space', [
            'id' => random_int(1, 9999),
            'uuid' => $uuid,
        ]);

        $this->assertSame($instance->uuid, $uuid);
        $this->assertEquals($instance->uuid, $uuid);


        $instance = $mapper->findOne('test_space', $instance->id);
        $this->assertNotSame($instance->uuid, $uuid);
        $this->assertEquals($instance->uuid, $uuid);

        // find using uuid instance
        $instance = $mapper->findOne('test_space', [ 'uuid' => $uuid ]);
        $this->assertEquals($instance->uuid, $uuid);

        // find using uuid string (type casting)
        $instance = $mapper->findOne('test_space', [ 'uuid' => (string) $uuid ]);
        $this->assertEquals($instance->uuid, $uuid);
    }
}
