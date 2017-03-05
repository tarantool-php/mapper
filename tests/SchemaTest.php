<?php

use Tarantool\Mapper\Space;

class SchemaTest extends TestCase
{
    public function testSystemMeta()
    {
        $mapper = $this->createMapper();

        $schema = $mapper->getSchema();

        $space = $schema->getSpace('_space');
        $this->assertInstanceOf(Space::class, $space);

        $this->assertTrue($space->hasProperty('id'));
        $this->assertFalse($space->hasProperty('uuid'));

        $this->assertSame($space->getPropertyType('id'), 'num');
    }

    public function testBasics()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper->getClient());

        $person = $mapper->getSchema()->createSpace('person');
        $person->addProperty('id', 'unsigned');
        $person->addProperty('name', 'str');
        $person->addProperty('birthday', 'unsigned');
        $person->addProperty('gender', 'str');

        // define type
        $person->createIndex([
            'type' => 'hash',
            'fields' => ['id'],
        ]);

        // create unique index
        $person->createIndex('name');

        // define unique
        $person->createIndex([
            'fields' => 'birthday',
            'unique' => false
        ]);

        $indexes = $mapper->find('_index', ['id' => $person->getId()]);
        $this->assertCount(3, $indexes);

        $person = $mapper->findOne('person', ['birthday' => '19840127']);
        $this->assertNull($person);

        $mapper->getClient()->setLogging(true);

        $nekufa = $mapper->getRepository('person')->create([
            'id' => 1,
            'name' => 'nekufa',
            'birthday' => '19840127',
        ]);

        $mapper->save($nekufa);

        $this->assertSame($nekufa->id, 1);
        $this->assertSame($nekufa->name, 'nekufa');
        $this->assertSame($nekufa->birthday, 19840127);

        $person = $mapper->findOne('person', ['birthday' => '19840127']);
        $this->assertSame($person, $nekufa);

        $nekufa->birthday = '19860127';
        $mapper->save($nekufa);
        $this->assertSame($nekufa->birthday, 19860127);

        $mapper->save($nekufa);

        $person = $mapper->findOne('person', ['birthday' => '19840127']);
        $this->assertNull($person);

        $person = $mapper->findOne('person', ['birthday' => '19860127']);
        $this->assertSame($person, $nekufa);

        $mapper->getClient()->setLogging(false);
        $this->assertCount(5, $mapper->getClient()->getLog());
        // create instance
        // select by birthday
        // update birthday
        // select by birthday
        // select by birthday
    }

    public function testIndexCasting()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper->getClient());

        $task = $mapper->getSchema()->createSpace('task');
        $task->addProperty('id', 'unsigned');
        $task->addProperty('year', 'unsigned');
        $task->addProperty('month', 'unsigned');
        $task->addProperty('day', 'unsigned');
        $task->addProperty('sector', 'unsigned');

        $task->createIndex('id');

        $task->createIndex([
            'fields' => ['year', 'month', 'day'],
            'unique' => false
        ]);

        $task->createIndex([
            'fields' => ['sector', 'year', 'month', 'day'],
            'unique' => false
        ]);

        $id = 1;

        $tasks = [
            ['id' => $id++, 'sector' => 1, 'year' => 2017, 'month' => 1, 'day' => 1],
            ['id' => $id++, 'sector' => 1, 'year' => 2017, 'month' => 1, 'day' => 1],
            ['id' => $id++, 'sector' => 1, 'year' => 2017, 'month' => 2, 'day' => 2],
            ['id' => $id++, 'sector' => 2, 'year' => 2017, 'month' => 1, 'day' => 2],
        ];

        foreach($tasks as $task) {
            $mapper->create('task', $task);
        }

        $this->assertCount(1, $mapper->find('task', ['sector' => 2]));
        $this->assertCount(3, $mapper->find('task', ['sector' => 1]));
        $this->assertCount(2, $mapper->find('task', ['sector' => 1, 'year' => 2017, 'month' => 1]));
        $this->assertCount(1, $mapper->find('task', ['sector' => 1, 'year' => 2017, 'month' => 2]));
        $this->assertCount(1, $mapper->find('task', ['year' => 2017, 'month' => 2]));
        $this->assertCount(3, $mapper->find('task', ['year' => 2017, 'month' => 1]));
        $this->assertCount(4, $mapper->find('task', ['year' => 2017]));
    }
}