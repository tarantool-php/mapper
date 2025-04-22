<?php

declare(strict_types=1);

namespace Tarantool\Mapper\Tests;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tarantool\Client\Client;
use Tarantool\Mapper\Mapper;
use Tarantool\Mapper\Pool;
use Tarantool\Mapper\Space;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class MapperTest extends TestCase
{
    private Middleware $middleware;

    public function createMapper(
        bool $arrays = false,
        bool $dropUserSpaces = true,
    ): Mapper {
        $host = getenv('TARANTOOL_HOST');
        $port = getenv('TARANTOOL_PORT') ?: 3301;
        $this->middleware = new Middleware();
        $client = Client::fromDsn("tcp://$host:$port")->withMiddleware($this->middleware);

        $mapper = new Mapper($client, arrays: $arrays, spy: true);
        if ($dropUserSpaces) {
            $mapper->dropUserSpaces();
        }

        return $mapper;
    }

    public function testUninitializedSpacePresent()
    {
        $this->assertTrue($this->createMapper(dropUserSpaces:false)->hasSpace('_space'));
    }

    public function testUninitializedSpaceList()
    {
        $this->assertNotCount(0, $this->createMapper(dropUserSpaces:false)->getSpaces());
    }

    public function testClassBased()
    {
        $mapper = $this->createMapper();

        $row = $mapper->findOrCreate(TypedConstructor::class, []);
        $this->assertEquals($row, $mapper->findOne('constructor'));

        $mapper = $this->createMapper(dropUserSpaces: false);
        $mapper->registerClass(TypedConstructor::class);
        $this->assertInstanceOf(TypedConstructor::class, $mapper->findOne('constructor'));
        $this->assertEquals($row, $mapper->findOne('constructor'));

        $constructor = $mapper->findOne('constructor');

        $mapper->update($constructor, ['nick' => 'space casting']);
        $this->assertSame($constructor->nick, 'space casting');

        $result = $mapper->delete($constructor);
        $this->assertNull($mapper->findOne('constructor'));
        $this->assertNotNull($result);
    }

    public function testAttribute()
    {
        $mapper = $this->createMapper();
        $attribute = $mapper->createSpace('attribute');
        $attribute->addProperty('key', 'string');
        $attribute->addProperty('value', 'string');
        $attribute->addIndex(['key']);

        $attribute = $mapper->findOrCreate('attribute', ['key' => 'tester']);
        $this->assertNotNull($attribute);
    }

    public function testIdleField()
    {
        $mapper = $this->createMapper();
        $tester = $mapper->createSpace('tester');
        $tester->addProperty('name', 'string');
        $tester->addProperty('idle', 'unsigned');
        $this->assertCount(0, $mapper->find('_index', ['id' => $tester->getId()]));
    }

    public function testUpdateStringPrimaryKey()
    {
        $mapper = $this->createMapper();
        $userdata = $mapper->createSpace('userdata');
        $userdata->addProperty('key', 'string');
        $userdata->addProperty('value', 'string');
        $userdata->addIndex(['key']);

        $name = $userdata->create(['key' => 'name', 'value' => 'nekufa']);
        $mapper->update('userdata', $name, ['value' => 'Dmitry Krokhin']);
        $this->assertSame($name->value, 'Dmitry Krokhin');
    }

    public function testDefaults()
    {
        $mapper = $this->createMapper();
        $tester = $mapper->createSpace('tester');
        $tester->addProperty('id', 'unsigned');
        $tester->addProperty('number', 'unsigned', ['default' => '1']);
        $tester->addProperty('string', 'string', ['default' => 'zzz']);

        $tester = $mapper->create('tester', []);
        $this->assertSame($tester->number, 1);
        $this->assertSame($tester->string, 'zzz');

        $tester = $mapper->create('tester', ['number' => 2, 'string' => 'www']);
        $this->assertSame($tester->number, 2);
        $this->assertSame($tester->string, 'www');
    }

    public function testCache()
    {
        $mapper = $this->createMapper(dropUserSpaces: false);
        $cache = new ArrayAdapter();
        $mapper->cache = $cache;
        $mapper->dropUserSpaces();
        $mapper->find('_vspace');

        $this->assertNotCount(0, $cache->getValues());

        $freshCounter = count($this->middleware->data);
        $mapper = $this->createMapper(dropUserSpaces: false);
        $mapper->cache = $cache;
        $mapper->dropUserSpaces();
        $mapper->find('_vspace');

        $this->assertLessThan($freshCounter, count($this->middleware->data));

        $cache = new ArrayAdapter();
        $mapper->getSpace('_vspace')->cache = $cache;

        $mapper->find('_vspace');
        $this->assertCount(1, $cache->getValues());
        $queries = count($this->middleware->data);

        $mapper->find('_vspace');
        $mapper->find('_vspace');
        $mapper->find('_vspace');
        $this->assertCount(1, $cache->getValues());
        $this->assertCount($queries, $this->middleware->data);

        $tester = $mapper->createSpace('tester');
        $tester->addProperty('id', 'unsigned');
        $tester->cache = new ArrayAdapter();
        $mapper->findOrCreate('tester', []); // created
        $queries = count($this->middleware->data);
        $mapper->findOrCreate('tester', []);
        $mapper->findOrCreate('tester', []);
        $mapper->findOrCreate('tester', []);
        $mapper->findOrCreate('tester', []);
        $this->assertCount($queries, $this->middleware->data);
    }

    public function testDifferentIndexPartConfiguration()
    {
        $mapper = $this->createMapper();

        $tester = $mapper->createSpace('tester');
        $tester->addProperty('id', 'unsigned');
        $tester->addProperty('name', 'string');

        $tester->addIndex(['name'], ['name' => 'first']);

        $mapper->client->call("box.space.tester:create_index", 'second', [
            'parts' => ['name']
        ]);

        $indexSpace = $mapper->getSpace('_index');

        $third = [
            'id' => $tester->getId(),
            'iid' => count($mapper->find('_vindex', ['id' => $tester->getId()])) + 1,
            'name' => 'third',
            'opts' => ['unique' => false],
            'type' => 'tree',
            'parts' => [
                ['field' => 1, 'type' => 'str']
            ]
        ];

        $mapper->client->call("box.space._index:insert", $indexSpace->getTuple($third));

        $property = new ReflectionProperty(Space::class, 'indexes');
        $property->setAccessible(true);
        $indexes = $property->getValue($tester);

        $this->assertSame($indexes[1]['fields'], $indexes[2]['fields']);
        $this->assertSame($indexes[1]['fields'], $indexes[3]['fields']);
    }

    public function testCreateRow()
    {
        $mapper = $this->createMapper();

        // No 'id' field, sequence isn't created
        $tester = $mapper->createSpace('tester');
        $format = [
            [
                'name' => 'key',
                'type' => 'string'
            ],[
                'name' => 'value',
                'type' => 'string'
            ]
        ];
        $tester->setFormat($format);
        $fields = $tester->getFields();
        $mapper->client->call("box.space.tester:format", $format);
        $tester->addIndex(['key'], ['name' => 'first']);
        $testRow = ['key' => 'green', 'value' => 'apple'];
        $tester->create($testRow);
        $result = $mapper->client->evaluate("return box.space.tester:select()")[0][0];
        $this->assertSame(array_values($testRow), array_values($result));
        $this->assertNull($mapper->client->evaluate('return box.sequence.tester')[0]);

        $tester->drop();

        // 'id' field present, but sequence isn't created
        $tester = $mapper->createSpace('tester');
        $format = [
            [
                'name' => 'id',
                'type' => 'unsigned'
            ],[
                'name' => 'value',
                'type' => 'string'
            ]
        ];
        $tester->setFormat($format);
        $mapper->client->call("box.space.tester:format", $format);
        $tester->addIndex(['id']);
        $testRow = ['value' => 'apple'];
        $tester->create($testRow);
        $result = $mapper->client->evaluate("return box.space.tester:select()")[0][0];
        $this->assertNotSame($result[0], 0);
        $this->assertSame($testRow['value'], $result[1]);
        $this->assertNotNull($mapper->client->evaluate('return box.sequence.tester')[0]);
        $tester->drop();

        // There is 'id' field and it is first, sequense is created
        $tester = $mapper->createSpace('tester');
        $tester->addProperty('id', 'unsigned');
        $tester->addProperty('name', 'string');
        $testRow = ['name' => 'Vasiliy'];
        $testRow2 = ['name' => 'Ivan'];
        $tester->create($testRow);
        $tester->create($testRow2);
        $result = $mapper->client->evaluate("return box.space.tester:select()")[0];
        $this->assertTrue($result[1][0] == 2);

        $tester->drop();

        // Field 'id' isn't first, sequense isn't created
        $tester = $mapper->createSpace('tester');
        $tester->addProperty('name', 'string');
        $tester->addProperty('id', 'unsigned');
        $tester->addIndex(['name']);

        $testRow = ['name' => 'Vasiliy'];
        $testRow2 = ['name' => 'Ivan'];
        $tester->create($testRow);
        $tester->create($testRow2);
        $result = $mapper->client->evaluate("return box.space.tester:select()")[0];
        $this->assertFalse($result[1][1] == 2);

        $tester->drop();
    }

    public function testTypeCasting()
    {
        $mapper = $this->createMapper(arrays: true);

        $tester = $mapper->createSpace('tester');
        $tester->addProperty('id', 'unsigned');
        $tester->addProperty('data', 'unsigned');

        $tester->addIndex(['id']);

        $testRow = $mapper->create('tester', [
            'id' => "1",
            'data' => "1",
        ]);

        $testRow2 = $mapper->create('tester', [
            'id' => "2",
            'data' => true,
        ]);

        $testRow3 = $mapper->create('tester', [
            'id' => "3",
            'data' => false,
        ]);

        // casting on create
        $this->assertSame($testRow['id'], 1);
        $this->assertNotSame($testRow['id'], "1");

        $this->assertSame($testRow['data'], 1);
        $this->assertNotSame($testRow['data'], "1");

        $this->assertSame($testRow2['data'], 1);
        $this->assertNotSame($testRow['data'], true);

        $this->assertSame($testRow3['data'], 0);
        $this->assertNotSame($testRow['data'], false);

        //casting on update
        $mapper->update('tester', $testRow, ['data' => false]);
        $mapper->update('tester', $testRow2, ['data' => "5"]);
        $mapper->update('tester', $testRow3, ['data' => true]);

        $testRow = $mapper->findOne('tester', ['id' => 1]);
        $testRow2 = $mapper->findOne('tester', ['id' => 2]);
        $testRow3 = $mapper->findOne('tester', ['id' => 3]);

        $this->assertSame($testRow['data'], 0);
        $this->assertNotSame($testRow['data'], false);

        $this->assertSame($testRow2['data'], 5);
        $this->assertNotSame($testRow2['data'], "5");

        $this->assertSame($testRow3['data'], 1);
        $this->assertNotSame($testRow3['data'], true);
    }

    public function testIndexCasting()
    {
        $mapper = $this->createMapper(arrays: true);
        $tester = $mapper->createSpace('tester');

        $tester->addProperty('id', 'unsigned');
        $tester->addProperty('name', 'string');
        $tester->addProperty('nick', 'string');
        $tester->addProperty('age', 'unsigned');
        $tester->addIndex(['name', 'nick', 'age']);

        $testRow = ['id' => 1, 'name' => 'Vladimir', 'nick' => 'vovan1', 'age' => 20];
        $tester->create($testRow);

        $row1 = $mapper->find('tester', ['name' => 'Vladimir', 'nick' => 'vovan1']);
        $row2 = $mapper->find('tester', ['nick' => 'vovan1', 'name' => 'Vladimir']);
        $row3 = $mapper->find('tester', ['name' => 'Vladimir', 'age' => 20, 'nick' => 'vovan1']);

        $this->assertSame($row1, $row2);
        $this->assertSame($row1, $row3);
        $tester->drop();
    }

    public function testFindOrCreateRow()
    {
        $mapper = $this->createMapper();
        $tester = $mapper->createSpace('tester');

        //id is not first field, sequence isn't created
        $format = [
            [
                'name' => 'idle',
                'type' => 'unsigned'
            ],[
                'name' => 'id',
                'type' => 'unsigned'
            ],[
                'name' => 'nick',
                'type' => 'string'
            ]
        ];
        $tester->setFormat($format);
        $mapper->client->call("box.space.tester:format", $format);
        $tester->addIndex(['nick']);
        $firstRow = $tester->findOrCreate(['nick' => 'Billy'], ['idle' => 0]);
        $secondRow = $tester->findOrCreate(['nick' => 'Jimmy'], ['idle' => 0]);
        $findRow = $tester->findOrCreate(['nick' => 'Billy']);
        $result = $mapper->client->evaluate("return box.space.tester.index.nick:select('Jimmy')")[0];
        $this->assertTrue($result[0][0] == 0);
        $this->assertSame($secondRow->id, $result[0][1]);
        $this->assertEquals($firstRow, $findRow);
        $tester->drop();

        //id is first field
        $tester = $mapper->createSpace('tester');
        $tester->addProperty('id', 'unsigned');
        $tester->addProperty('idle', 'unsigned');
        $tester->addProperty('nick', 'string');
        $tester->addIndex(['nick']);
        $tester->findOrCreate(['nick' => 'Billy'], ['idle' => 0]);
        $secondRow = $tester->findOrCreate(['nick' => 'Jimmy'], ['idle' => 0]);
        $findRow = $tester->findOrCreate(['nick' => 'Jimmy']);
        $result = $mapper->client->evaluate("return box.space.tester.index.nick:select('Jimmy')")[0];
        $this->assertTrue($result[0][0] == 2);
        $this->assertSame($secondRow->id, $result[0][0]);
        $this->assertEquals($secondRow, $findRow);
        $tester->drop();
    }

    public function testLua()
    {
        $mapper = $this->createMapper();
        foreach ($mapper->find('_vfunc') as $func) {
            if (strpos($func->name, 'evaluate_') === 0) {
                $mapper->client->call('box.schema.func.drop', $func->name);
            }
        }

        $functionsLength = count($mapper->find('_vfunc'));

        [$result] = $mapper->evaluate(
            "return a + b",
            ['a' => 1, 'b' => 2]
        );
        $this->assertSame($result, 3);
        $this->assertSame($functionsLength, count($mapper->find('_vfunc')));

        [$result] = $mapper->call(
            "return a + b",
            ['a' => 1, 'b' => 2]
        );
        $this->assertSame($functionsLength + 1, count($mapper->find('_vfunc')));
    }

    public function testSpaces()
    {
        $log = new Logger('test');
        $log->pushHandler(new StreamHandler('php://output'));
        echo PHP_EOL;
        $mapper = $this->createMapper();


        $userTypes = [
            'constructor' => TypedConstructor::class, // static space name
            'typed_properties' => TypedProperties::class, // class basd space name
        ];

        foreach ($userTypes as $name => $type) {
            foreach ([$name, $type] as $target) {
                $space = $mapper->createSpace($target);
                $space->setClass($type);
                $space->migrate();
                $space->migrate();
                $this->assertSame($space->getName(), $name);

                $this->assertSame($space, $mapper->createSpace($target, ['if_not_exists' => true]));
                $this->assertSame($space, $mapper->getSpace($space->getId()));
                $this->assertCount(2, $mapper->find('_vindex', ['id' => $space->getId()]));
                $space->drop();
            }

            $space = $mapper->createSpace($name);
            $space->setClass($type);
            $space->migrate();
        }

        $space = $mapper->createSpace('array');
        $space->addProperty('id', 'unsigned');
        $space->addProperty('name', 'string');
        $space->addProperty('nick', 'string', ['default' => 'nick']);

        $space = $mapper->createSpace('object');
        $space->addProperty('id', 'unsigned');
        $space->addProperty('name', 'string');
        $space->addProperty('nick', 'string', ['default' => 'nick']);

        $todo = array_keys($userTypes);
        $todo[] = 'array';
        $todo[] = 'object';

        foreach ($todo as $nick) {
            $mapper->arrays = $nick == 'array';
            $space = $mapper->getSpace($nick);
            $this->assertSame($space->getFields(), ['id', 'name', 'nick']);
            $this->assertEquals($space->getFieldFormat('id'), [
                'name' => 'id',
                'type' => 'unsigned',
                'is_nullable' => false,
            ]);
            $this->assertEquals($space->getFieldFormat('name'), [
                'name' => 'name',
                'type' => 'string',
            ]);
            $this->assertEquals($space->getFieldFormat('nick'), [
                'name' => 'nick',
                'type' => 'string',
                'default' => 'nick',
            ]);

            $instance = $mapper->create($nick, ['name' => 'tester']);
            $tuple = $space->getTuple($instance);
            $this->assertSame($tuple[0], 1);
            $this->assertSame($tuple[2], 'nick');

            $start = microtime(true);
            $length = 100_000;
            foreach (range(1, $length) as $_) {
                $space->getInstance($tuple);
            }
            $log->info("instance benchmark", [
                'space' => $nick,
                'length' => $length,
                'time' => microtime(true) - $start,
                'ips' => (int) ($length / (microtime(true) - $start)),
            ]);

            $instance = $mapper->create($nick, ['name' => 'tester', 'nick' => 'tester']);
            $tuple = $space->getTuple($instance);
            $this->assertSame($tuple[0], 2);
            $this->assertSame($tuple[2], 'tester');
            $this->assertCount(2, $space->find());

            $space->addIndex(['nick'], ['unique' => true]);
            $instance = $mapper->findOrCreate($nick, ['nick' => 'nekufa'], ['name' => 'Dmitry']);
            $tuple = $space->getTuple($instance);
            $this->assertSame($tuple[0], 3);
            $this->assertSame($tuple[1], 'Dmitry');
            $this->assertSame($tuple[2], 'nekufa');

            $instance = $mapper->findOrCreate($nick, ['nick' => 'nekufa']);
            $tuple = $space->getTuple($instance);
            $this->assertSame($tuple[0], 3);

            $instance = $mapper->findOrFail($nick, ['nick' => 'nekufa']);
            $this->assertNotNull($instance);
            $tuple = $space->getTuple($instance);
            $this->assertSame($tuple[0], 3);

            $updated = $space->update($instance, ['name' => 'Dmitry Krokhin']);
            if (is_object($updated)) {
                $this->assertSame($instance->name, $updated->name);
            }
            $tuple = $space->getTuple($updated);
            $this->assertSame($tuple[1], 'Dmitry Krokhin');

            $instance = $mapper->findOne($nick, ['nick' => 'bazyaba']);
            $this->assertNull($instance);

            $this->assertNotNull($mapper->get($nick, 1));
            $this->assertNotNull($mapper->get($nick, 2));

            $space->delete($space->findOrFail(['nick' => 'tester']));

            $this->assertCount(2, $mapper->getChanges());

            foreach ($mapper->getChanges() as $change) {
                $this->assertSame($change->type, 'insert');
                $this->assertSame($change->space, $nick);
                if ($change->data['id'] == 3) {
                    // merge insert and updates into single insert
                    $this->assertSame($change->data['name'], 'Dmitry Krokhin');
                }
            }
            $mapper->flushChanges();
            $instance = $mapper->create($nick, ['nick' => 'mapper.delete']);
            $this->assertCount(1, $mapper->getChanges());
            $this->assertCount(1, $mapper->getChanges(), 'changes keep untill flush');
            $mapper->delete($nick, $instance);
            $this->assertCount(0, $mapper->getChanges(), 'insert and delete merges into nothing');
        }

        $pool = new Pool(function () use ($mapper) {
            return $mapper;
        }, function ($instance) use ($mapper) {
            $name = get_class($instance)::getSpaceName();
            return "prefix.$name";
        });

        $pool->create('first.array', ['nick' => 'qwerty']);
        $constructor = $pool->create('second.constructor', ['nick' => 'asdf']);

        $changes = $pool->getChanges();
        $this->assertCount(4, $changes);
        $this->assertSame($changes[0]->space, 'first.array');
        $this->assertSame($changes[2]->space, 'second.array');

        // validate pool space casting
        $pool->update($constructor, ['nick' => 'tester']);
    }
}
