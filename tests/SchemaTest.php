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
        $this->clean($mapper);

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

        $person->createIndex([
            'fields' => ['name', 'birthday'],
            'type' => 'hash'
        ]);


        $indexes = $mapper->find('_index', ['id' => $person->getId()]);
        $this->assertCount(4, $indexes);

        list($id, $name, $birthday, $nameBirthday) = $indexes;
        $this->assertSame($id->iid, 0);
        $this->assertSame($birthday->type, 'tree');
        $this->assertSame($nameBirthday->type, 'hash');


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
        $this->assertFalse(property_exists($nekufa, 'gender'));

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

        $vasiliy = $mapper->create('person', [
            'id' => 2,
            'name' => 'vasiliy',
            'gender' => 'male',
        ]);

        $this->assertNotNull($vasiliy);
        $this->assertSame($vasiliy->birthday, 0);
    }

    public function testIndexCasting()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

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
            'unique' => false,
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

        $anotherMapper = $this->createMapper();

        $indexes = $anotherMapper->getSchema()->getSpace('task')->getIndexes();
        $this->assertCount(3, $indexes);
        list($id, $ymd, $symd) = $indexes;
        $this->assertSame($id->name, 'id');
        $this->assertSame($id->parts, [[0, 'unsigned']]);
        $this->assertSame($ymd->name, 'year_month_day');
        $this->assertSame($ymd->parts, [[1, 'unsigned'], [2, 'unsigned'], [3, 'unsigned']]);
        $this->assertSame($symd->name, 'sector_year_month_day');
    }
}