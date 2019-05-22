<?php

use Psr\Log\AbstractLogger;
use Tarantool\Client\Client;
use Tarantool\Client\Middleware\FirewallMiddleware;
use Tarantool\Client\Middleware\LoggingMiddleware;
use Tarantool\Client\Request\InsertRequest;
use Tarantool\Mapper\Mapper;
use Tarantool\Mapper\Plugin\Sequence;
use Tarantool\Mapper\Schema;

class MapperTest extends TestCase
{
    public function testVinylEngine()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $tester = $mapper->getSchema()
            ->createSpace('tester', [
                'engine' => 'vinyl',
                'properties' => [
                    'id' => 'unsigned',
                    'value' => '*',
                ]
            ])
            ->addIndex([
                'fields' => ['id'],
                'type' => 'tree'
            ]);

        $this->assertSame($tester->getEngine(), 'vinyl');
    }

    public function testNullableColumns()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $tester = $mapper->getSchema()
            ->createSpace('tester', [
                'id' => 'unsigned',
                'value' => '*',
            ])
            ->addIndex([
                'fields' => ['id'],
                'type' => 'hash'
            ]);

        $this->assertTrue($tester->isPropertyNullable('value'));
        $this->assertSame($tester->getEngine(), 'memtx');

        $instance = $mapper->findOrCreate('tester', ['id' => 1]);
        $this->assertNull($instance->value);
    }

    public function testFindAllUsingHashIndex()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->getSchema()
            ->createSpace('tester', [
                'id' => 'unsigned',
                'name' => 'string',
            ])
            ->addIndex([
                'fields' => ['id'],
                'type' => 'hash'
            ]);

        $mapper->create('tester', [1, 'hello world']);

        $this->assertCount(1, $mapper->find('tester'));
    }

    public function testRemoveAndCreate()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->getSchema()
            ->createSpace('tester', [
                'a' => 'unsigned',
                'b' => 'unsigned',
            ])
            ->addIndex(['a', 'b']);

        $tester1 = $mapper->create('tester', ['a' => 1, 'b' => 2]);

        [$tester1x] = $mapper->find('tester', ['a' => 1]);
        $this->assertSame($tester1, $tester1x);
        $mapper->remove($tester1x);

        $tester2 = $mapper->create('tester', ['a' => 1, 'b' => 2]);

        $this->assertSame($tester1->a, $tester2->a);
        $this->assertSame($tester1->b, $tester2->b);
        $this->assertNotSame($tester1, $tester2);
    }

    public function testDoubleCreation()
    {
        $mapper = $this->createMapper();
        $mapper->getPlugin(new Sequence($mapper));
        $this->clean($mapper);

        $mapper->getSchema()
            ->createSpace('tester', [
                'id'    => 'unsigned',
                'label' => 'string',
            ])
            ->addIndex('id')
            ->addIndex('label');

        $this->expectExceptionMessage('tester 1 exists');

        $first = $mapper->create('tester', [
            'id' => 1,
            'label' => 1
        ]);

        $second = $mapper->create('tester', [
            'id' => 1,
            'label' => 2
        ]);
    }

    public function testFindOrCreateWithoutSequence()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->getSchema()
            ->createSpace('tester', [
                'flow'    => 'unsigned',
                'entityId'    => 'unsigned',
            ])
            ->addIndex(['flow', 'entityId']);

        $instance = $mapper->findOne('tester', ['flow' => 1, 'entityId' => 2]);
        $instance = $mapper->findOrCreate('tester', ['flow' => 1, 'entityId' => 2]);
        $this->assertNotNull($instance);
        $this->assertSame($instance->flow, 1);

        $anotherMapper = $this->createMapper();
        $anotherInstance = $anotherMapper->findOrCreate('tester', ['flow' => 1, 'entityId' => 2]);

        $this->assertNotNull($anotherInstance);
        $this->assertSame($anotherInstance->flow, $instance->flow);
    }

    public function testMultiUpdates()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->getSchema()
            ->createSpace('tester', [
                'id' => 'unsigned',
                'a'  => 'string',
                'b'  => 'string',
            ])
            ->addIndex('id');

        $tester = $mapper->findOrCreate('tester', [ 'id' => 1 ]);
        $tester->a = 'a';
        $tester->b = 'b';
        $tester->save();

        $mapper = $this->createMapper();
        $tester = $mapper->findOrCreate('tester', [ 'id' => 1 ]);
        $this->assertSame($tester->a, 'a');
        $this->assertSame($tester->b, 'b');
    }

    public function testFindOrCreateShortcut()
    {
        $mapper = $this->createMapper();
        $mapper->getPlugin(new Sequence($mapper));
        $this->clean($mapper);

        $mapper->getSchema()
            ->createSpace('tester', [
                'idle'  => 'unsigned',
                'id'    => 'unsigned',
                'label' => 'string',
            ])
            ->addIndex('id')
            ->addIndex('label');

        $params = [
            'label' => 'test'
        ];

        $first = $mapper->findOrCreate('tester', $params);
        $this->assertNotNull($first);
        $this->assertSame($first, $mapper->findOrCreate('tester', $params));
        $this->assertCount(1, $mapper->find('tester'));

        $anotherMapper = $this->createMapper();
        $anotherMapper->getPlugin(new Sequence($mapper));
        $anotherEntity = $anotherMapper->findOrCreate('tester', $params);
        $this->assertSame($anotherEntity->id, $first->id);

        $params = ['label' => 'zzz'];
        $second = $mapper->findOrCreate('tester', $params);
        $this->assertNotNull($second);
        $this->assertSame($second, $mapper->findOrCreate('tester', $params));
        $this->assertCount(2, $mapper->find('tester'));

    }

    public function testFindOrFail()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);
        $mapper->getPlugin(Sequence::class);

        $mapper->getSchema()
            ->createSpace('tester', [
                'id'    => 'unsigned',
                'label' => 'string',
            ])
            ->addIndex('id')
            ->addIndex('label');

        $this->expectException(Exception::class);
        $mapper->findOrFail('tester', ['label' => 'test']);
    }

    public function testFirstTupleValueIndexCasting()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $client = $mapper->getClient();

        $space = $mapper->getSchema()
            ->createSpace('tester', [
                'label' => 'string',
                'id' => 'unsigned'
            ])
            ->addIndex('id')
            ->addIndex('label');

        $this->assertSame(0, $space->castIndex(['id' => 1]));
        $this->assertSame(1, $space->castIndex(['label' => 1]));
    }

    public function testComplexIndexCasting()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $client = $mapper->getClient();

        $space = $mapper->getSchema()
            ->createSpace('stage', [
                'id' => 'unsigned',
                'year' => 'unsigned',
                'month' => 'unsigned',
                'day' => 'unsigned',
            ])
            ->addIndex('id')
            ->addIndex(['year', 'month', 'day']);

        $this->assertSame(0, $space->castIndex(['id' => 1]));
        $this->assertSame(1, $space->castIndex(['year' => 1, 'month' => 2]));
        $this->assertSame(1, $space->castIndex(['month' => 2, 'year' => 1]));
    }

    public function testArray()
    {
        $mapper = $this->createMapper();
        $client = $mapper->getClient();

        $mapper->getSchema()
            ->createSpace('params', [
                'id' => 'unsigned',
                'arr' => '*'
            ])
            ->addIndex('id');

        $mapper->create('params', [
            'id' => 1,
            'arr' => [1,2,3,4,5],
        ]);

        $mapper = $this->createMapper();
        $this->assertSame([1,2,3,4,5], $mapper->findOne('params', 1)->arr);
    }

    public function testDisableRequestType()
    {
        $firewall = FirewallMiddleware::allowReadOnly();
        $mapper = $this->createMapper($firewall);

        $client = $mapper->getClient();

        $this->assertNotCount(0, $mapper->find('_space'));

        $this->expectException(Exception::class);

        $mapper->getSchema()->createSpace('tester')
            ->addProperties([
                'id' => 'unsigned',
                'name' => 'string',
            ])
            ->addIndex(['id']);

        $tester = $mapper->create('tester', [
            'id' => 1,
            'name' => 'hello'
        ]);

        $tester->name = 'hello world';
        $tester->save();
    }

    public function testLogging()
    {
        $logger = new Logger();
        $mapper = $this->createMapper(new LoggingMiddleware($logger));
        $logger->flush();
        $this->assertCount(0, $logger->getLog());

        $mapper->getClient()->ping();

        // ping
        $this->assertCount(1, $logger->getLog());

        $logger->flush();
        $this->assertCount(0, $logger->getLog());
    }

    public function testTypeCasting()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->getSchema()
            ->createSpace('rules', [
                'id' => 'unsigned',
                'module' => 'unsigned',
                'rule' => 'unsigned',
            ])
            ->addIndex('id')
            ->addIndex('module');

        $rule = $mapper->create('rules', [
            'id' => "1",
            'module' => "1"
        ]);
        // casting on create
        $this->assertSame($rule->id, 1);
        $this->assertNotSame($rule->id, "1");

        $this->assertSame($rule->module, 1);
        $this->assertNotSame($rule->module, "1");

        $rule->rule = "2";
        $this->assertSame($rule->rule, "2");
        $this->assertNotSame($rule->rule, 2);
        $rule->save();

        // casting on update
        $this->assertSame($rule->rule, 2);
        $this->assertNotSame($rule->rule, "2");
    }
    public function testNullIndexedValuesFilling()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->getSchema()
            ->createSpace('rules', [
                'id' => 'unsigned',
                'module' => 'unsigned',
                'rule' => 'unsigned',
            ])
            ->addIndex('id')
            ->addIndex('module')
            ->addIndex('rule');

        $rule = $mapper->create('rules', [
            'id' => 1,
            'module' => 1
        ]);
        $this->assertSame($rule->rule, 0);
    }

    public function testTypesCasting()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->getSchema()
            ->createSpace('tester', [
                'id' => 'unsigned',
                'name' => 'string',
            ])
            ->addIndex('id')
            ->addIndex('name');

        $petya = $mapper->create('tester', [
            'id' => '1',
            'name' => 'petya',
        ]);
        $this->assertSame(1, $petya->id);

        $anotherPetya = $mapper->findOne('tester', ['id' => '1']);
        $this->assertNotNull($anotherPetya);
    }
    public function testIndexRemoved()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->getSchema()
            ->createSpace('tester', [
                'id' => 'unsigned',
                'name1' => 'string',
                'name2' => 'string',
            ])
            ->addIndex('id')
            ->addIndex('name1')
            ->addIndex('name2')
            ->removeIndex('name1');

        $mapper->create('tester', [
            'id' => 1,
            'name1' => 'q',
            'name2' => 'w'
        ]);

        $mapper = $this->createMapper();
        $this->assertNotNull($mapper->findOne('tester', ['name2' => 'w']));
    }

    public function testPropertyNameCasting()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->getPlugin(Sequence::class);

        $mapper->getSchema()
            ->createSpace('person', [
                'id' => 'unsigned',
                'name' => 'string',
                'children' => '*',
            ])
            ->addIndex(['id']);

        $dmitry = $mapper->create('person', ['dmitry', [1, 2]]);
        $this->assertSame(1, $dmitry->id);
        $this->assertSame('dmitry', $dmitry->name);
    }

    public function testActiveEntity()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->getSchema()->createSpace('tester')
            ->addProperties([
                'id' => 'unsigned',
                'name' => 'string',
            ])
            ->addIndex(['id']);

        $tester = $mapper->create('tester', [
            'id' => 1,
            'name' => 'hello'
        ]);

        $tester->name = 'hello world';
        $tester->save();

        $mapper = $this->createMapper();
        $entity = $mapper->findOne('tester', 1);
        $this->assertSame($entity->name, 'hello world');
    }

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

            ->addProperty('uuid', 'string')
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
                'uuid'        => 'string',
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
        $session->addProperty('uuid', 'string');
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

        $mapper->findOne('_space', ['name' => '_space']);
        $mapper->findOne('_space', ['id' => $_space->id]);

        $data = $mapper->find('_space', ['id' => $_space->id]);
        $this->assertCount(1, $data);
    }
}
