<?php

use Tarantool\Mapper\Client;
use Tarantool\Mapper\Mapper;
use Tarantool\Mapper\Schema;

class MapperTest extends TestCase
{
    public function testRemove()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->getSchema()->createSpace('sector_parent')
            ->addProperties([
                'id' => 'unsigned',
                'parent' => 'unsigned',
            ])
            ->addIndex(['id', 'parent']);

        $mapper->create('sector_parent', ['id' => 1, 'parent' => 2]);
        $mapper->create('sector_parent', ['id' => 1, 'parent' => 3]);
        $mapper->create('sector_parent', ['id' => 2, 'parent' => 3]);

        $this->assertCount(3, $mapper->find('sector_parent'));

        $mapper->remove('sector_parent', ['id' => 1]);
        $this->assertCount(1, $mapper->find('sector_parent'));

        $mapper->getRepository('sector_parent')->truncate();
        $this->assertCount(0, $mapper->find('sector_parent'));
    }

    public function testCompositeKeys()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->getSchema()->createSpace('sector_parent')
            ->addProperties([
                'id' => 'unsigned',
                'parent' => 'unsigned',
            ])
            ->addIndex(['id', 'parent']);

        $mapper->create('sector_parent', ['id' => 1, 'parent' => 2]);
        $mapper->create('sector_parent', ['id' => 1, 'parent' => 3]);

        $this->assertCount(2, $mapper->find('sector_parent'));
    }

    public function testFluentInterface()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->getSchema()->createSpace('session')

            ->addProperty('uuid', 'str')
            ->addProperty('activity_at', 'unsigned')
            ->addProperty('login', 'unsigned')
            ->addProperty('ip', 'unsigned')

            ->createIndex('uuid')

            ->createIndex([
                'fields' => 'login',
                'unique' => false,
            ])

            ->createIndex([
                'fields' => 'ip',
                'unique' => false,
            ]);

        $entity = $mapper->create('session', [
            'uuid' => '81b3edc8-0dd0-43b6-80b4-39f1f8045f3e',
            'login' => 1,
            'ip' => 2130706433
        ]);

        $this->assertNotNull($entity);
    }

    public function testArrayPropertyCreation()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->getSchema()->createSpace('session')

            ->addProperties([
                'uuid'        => 'str',
                'activity_at' => 'unsigned',
                'login'       => 'unsigned',
                'ip'          => 'unsigned',
            ])

            ->addIndex('uuid')
            ->addIndex([
                'fields' => 'login',
                'unique' => false,
            ])
            ->addIndex([
                'fields' => 'ip',
                'unique' => false,
            ]);

        $entity = $mapper->create('session', [
            'uuid' => '81b3edc8-0dd0-43b6-80b4-39f1f8045f3e',
            'login' => 1,
            'ip' => 2130706433
        ]);

        $this->assertNotNull($entity);
    }

    public function testBasics()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $schema = $mapper->getSchema();

        $session = $schema->createSpace('session');
        $session->addProperty('uuid', 'str');
        $session->addProperty('activity_at', 'unsigned');
        $session->addProperty('login', 'unsigned');
        $session->addProperty('ip', 'unsigned');

        $session->createIndex('uuid');
        $session->createIndex([
            'fields' => 'login',
            'unique' => false,
        ]);

        $session->createIndex([
            'fields' => 'ip',
            'unique' => false,
        ]);

        $entity = $mapper->create('session', [
            'uuid' => '81b3edc8-0dd0-43b6-80b4-39f1f8045f3e',
            'login' => 1,
            'ip' => 2130706433
        ]);

        $this->assertNotNull($entity);
    }

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
