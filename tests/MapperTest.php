<?php

use Tarantool\Mapper\Client;
use Tarantool\Mapper\Mapper;
use Tarantool\Mapper\Schema;

class MapperTest extends TestCase
{
    public function testInstances()
    {
        $mapper = $this->createMapper();
        $this->assertInstanceOf(Mapper::class, $mapper);

        $schema = $mapper->getSchema();
        $this->assertInstanceOf(Schema::class, $schema);

        $client = $mapper->getClient();
        $this->assertInstanceOf(Client::class, $client);
    }

    public function testMapping()
    {
        $mapper = $this->createMapper();

        $spaceInstance = $mapper->findOne('_space', ['id' => 280]);
        $anotherInstance = $mapper->getRepository('_space')->findOne(['name' => '_space']);

        $this->assertSame($spaceInstance, $anotherInstance);

        // validate _space row
        $this->assertSame($spaceInstance->id, 280);
        $this->assertSame($spaceInstance->owner, 1);
        $this->assertSame($spaceInstance->name, '_space');
        $this->assertSame($spaceInstance->engine, 'memtx');

        // validate collections
        $spaceData = $mapper->getRepository('_space')->find(['name' => '_space']);
        $anotherData = $mapper->find('_space', ['name' => '_space']);
        $this->assertSame($spaceData, $anotherData);

        // validate identity map
        $this->assertSame($spaceData[0], $spaceInstance);

        $guest = $mapper->findOne('_user', ['id' => 0]);
        $this->assertSame($guest->name, 'guest');
        $this->assertSame($guest->type, 'user');

        $guests = $mapper->find('_user', ['name' => 'guest']);
        $this->assertCount(1, $guests);
        $this->assertSame($guests[0], $guest);

        // validate get by id easy call
        $user = $mapper->findOne('_user', [0]);
        $this->assertSame($user, $guest);

        $user = $mapper->findOne('_user', 0);
        $this->assertSame($user, $guest);
    }

    public function testQueryCaching()
    {
        $mapper = $this->createMapper();
        $client = $mapper->getClient();
        $this->assertInstanceOf(Client::class, $client);

        $_space = $mapper->findOne('_space', ['name' => '_space']);

        $client->setLogging(true);
        $mapper->findOne('_space', ['name' => '_space']);
        $mapper->findOne('_space', ['id' => $_space->id]);

        $data = $mapper->find('_space', ['id' => $_space->id]);
        $this->assertCount(1, $data);

        $this->assertCount(0, $client->getLog());
    }
}