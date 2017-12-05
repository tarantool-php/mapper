<?php

use Tarantool\Mapper\Mapper;
use Tarantool\Mapper\Space;
use Tarantool\Mapper\Plugin\Sequence;

class SchemaTest extends TestCase
{
    public function testDynamicIndexCreation()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->getPlugin(Sequence::class);

        $tester = $mapper->getSchema()->createSpace('tester', [
                'id' => 'unsigned',
                'name' => 'string',
            ])
            ->addIndex('id');

        $tester->setPropertyNullable('name', false);

        $mapper->create('tester', 'Dmitry');

        try {
            $tester->castIndex(['name' => 'Peter']);
            $this->assertNull("Index not exists");
        } catch (Exception $e) {
            $this->assertNotNull("Index not exists");
            $tester->createIndex('name');
        }

        try {
            $tester->castIndex(['name' => 'Peter']);
            $this->assertNotNull("Index exists");
        } catch (Exception $e) {
            $this->assertNull("Index exists");
            $tester->createIndex('name');
        }
    }

    public function testCamelCased()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->getPlugin(Sequence::class);

        $tester = $mapper->getSchema()->createSpace('tester', [
                'id' => 'unsigned',
                'firstName' => 'string',
            ])
            ->addIndex('id')
            ->addIndex('firstName');

        $d = $mapper->findOrCreate('tester', ['firstName' => 'Dmitry']);

        $this->assertTrue($tester->hasProperty('firstName'));
        $this->assertFalse($tester->hasProperty('first_name'));

        $this->assertNotNull($d);
        $this->assertNotNull($d->firstName);

        $this->assertSame($this->createMapper()->findOne('tester', ['id' => $d->id])->firstName, $d->firstName);
    }

    public function testCreateSpaceWithProperties()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $tester = $mapper->getSchema()->createSpace('tester', [
            'id' => 'unsigned',
            'name' => 'string',
        ]);

        $tester->addIndex('id');

        $this->assertCount(2, $tester->getFormat());
    }

    public function testDuplicateProperty()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $space = $mapper->getSchema()->createSpace('tester');
        $space->addProperty('id', 'unsigned');
        $this->expectException(Exception::class);

        $space->addProperty('id', 'unsigned');
    }

    public function testRemoveProperty()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $space = $mapper->getSchema()->createSpace('tester');
        $space->addProperty('first', 'unsigned');
        $space->addProperty('second', 'unsigned');
        $space->addProperty('third', 'unsigned');

        $this->assertCount(3, $space->getFormat());

        $space->removeProperty('third');
        $this->assertCount(2, $space->getFormat());

        $this->expectException(Exception::class);
        $space->removeIndex('first');
    }

    public function testRemoveIndex()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $space = $mapper->getSchema()->createSpace('tester');
        $space->addProperty('id', 'unsigned');
        $space->addProperty('iid', 'unsigned');

        $space->createIndex(['id']);
        $space->createIndex(['iid']);

        $this->assertCount(2, $space->getIndexes());

        $space->removeIndex('iid');
        $this->assertCount(1, $space->getIndexes());

        $this->expectException(Exception::class);
        $space->removeIndex('iid');
    }

    public function testNoSpaceException()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);
        $this->expectException(Exception::class);
        $space = $mapper->getSchema()->getSpace(null);
    }

    public function testEmptySpace()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $tester = $mapper->getSchema()->createSpace('tester');
        $this->assertCount(0, $tester->getFormat());
        $this->assertCount(0, $tester->getIndexes());
    }

    public function testIndexes()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $schema = $mapper->getSchema();

        $test = $schema->createSpace('test');
        $test->addProperty('a', 'unsigned');
        $test->addProperty('b', 'unsigned');
        $test->createIndex(['a', 'b']);

        $indexes = $mapper->find('_index', [
            'id' => $test->getId()
        ]);

        $this->assertCount(1, $indexes);
    }

    public function testOnce()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $schema = $mapper->getSchema();

        $test = $schema->createSpace('test');
        $test->addProperty('name', 'string');
        $test->createIndex('name');

        $flag = $mapper->findOne('_schema', ['key' => 'onceinsert']);
        if ($flag) {
            $mapper->remove($flag);
        }

        $iterations = 2;
        while ($iterations--) {
            $schema->once('insert', function (Mapper $mapper) {
                $mapper->create('test', ['name' => 'example row']);
            });
        }
        $this->assertCount(1, $mapper->find('test'));
    }

    public function testSystemMeta()
    {
        $mapper = $this->createMapper();

        $schema = $mapper->getSchema();

        $space = $schema->getSpace('_space');
        $this->assertInstanceOf(Space::class, $space);

        $this->assertTrue($space->hasProperty('id'));
        $this->assertFalse($space->hasProperty('uuid'));

        $this->assertContains($space->getPropertyType('id'), ['num', 'unsigned']);
    }

    public function testBasics()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $person = $mapper->getSchema()->createSpace('person');
        $person->addProperty('id', 'unsigned');
        $person->addProperty('name', 'string');
        $person->addProperty('birthday', 'unsigned');
        $person->addProperty('gender', 'string');

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
        $this->assertNull($nekufa->gender);

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

        $mapper->remove($vasiliy);

        $this->assertNull($mapper->findOne('person', ['name' => 'vasiliy']));
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

        foreach ($tasks as $task) {
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
        $this->assertSame($id['name'], 'id');
        $this->assertSame($id['parts'], [[0, 'unsigned']]);
        $this->assertSame($ymd['name'], 'year_month_day');
        $this->assertSame($ymd['parts'], [[1, 'unsigned'], [2, 'unsigned'], [3, 'unsigned']]);
        $this->assertSame($symd['name'], 'sector_year_month_day');

        $this->expectExceptionMessage("No index on task for [day]");
        $anotherMapper->find('task', ['day' => 1]);
    }

    public function testNoIndexMessageForMultipleProperties()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $task = $mapper->getSchema()->createSpace('task');
        $task->addProperty('id', 'unsigned');
        $task->addProperty('year', 'unsigned');
        $task->addProperty('month', 'unsigned');
        $task->createIndex('id');

        $this->expectExceptionMessage("No index on task for [year, month]");
        $mapper->find('task', ['year' => 2017, 'month' => 12]);
    }
}
