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
    public function createMapper(): Mapper
    {
        $host = getenv('TARANTOOL_HOST');
        $port = getenv('TARANTOOL_PORT') ?: 3301;
        $this->middleware = new Middleware();
        $client = Client::fromDsn("tcp://$host:$port")->withMiddleware($this->middleware);

        $mapper = new Mapper($client);
        $mapper->spy = true;
        return $mapper;
    }

    public function testCache()
    {
        $mapper = $this->createMapper();
        $cache = new ArrayAdapter();
        $mapper->cache = $cache;
        $this->assertCount(0, $cache->getvalues());
        $mapper->find('_vspace');

        $this->assertNotCount(0, $cache->getvalues());

        $freshCounter = count($this->middleware->data);

        $mapper = $this->createMapper();
        $mapper->cache = $cache;
        $mapper->find('_vspace');

        // 4 requests:
        // - schema id 0 space + index
        // - schema id N space + index
        $this->assertCount($freshCounter - 4, $this->middleware->data);
    }

    public function testDifferentIndexPartConfiguration()
    {
        $mapper = $this->createMapper();
        foreach ($mapper->find('_vspace') as $space) {
            if ($space['id'] >= 512) {
                $mapper->getSpace($space['id'])->drop();
            }
        }

        $tester = $mapper->createSpace('tester');
        $tester->addProperty('id', 'unsigned');
        $tester->addProperty('name', 'string');

        $tester->addIndex(['name'], ['name'=>'first']);

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
                ['field'=> 1, 'type' => 'str']
            ]
        ];

        $mapper->client->call("box.space._index:insert", $indexSpace->getTuple($third));

        $property = new ReflectionProperty(Space::class, 'indexes');
        $property->setAccessible(true);
        $indexes = $property->getValue($tester);

        $this->assertSame($indexes[1]['fields'], $indexes[2]['fields']);
        $this->assertSame($indexes[1]['fields'], $indexes[3]['fields']);
    }

    public function testLua()
    {
        $mapper = $this->createMapper();
        foreach ($mapper->find('_vfunc') as $func) {
            if (strpos($func['name'], 'evaluate_') === 0) {
                $mapper->client->call('box.schema.func.drop', $func['name']);
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

        foreach ($mapper->find('_vspace') as $space) {
            if ($space['id'] >= 512) {
                $mapper->getSpace($space['id'])->drop();
            }
        }

        $userTypes = [
            'constructor' => TypedConstructor::class,
            'properties' => TypedProperties::class,
        ];

        foreach ($userTypes as $name => $type) {
            $space = $mapper->createSpace($name);
            $space->setClass($type);
            $space->migrate();
            $space->migrate();

            $this->assertSame($space, $mapper->createSpace($name, ['if_not_exists' => true]));
            $this->assertSame($space, $mapper->getSpace($space->getId()));
            $this->assertCount(2, $mapper->find('_vindex', ['id' => $space->getId()]));
        }

        $space = $mapper->createSpace('array');
        $space->addProperty('id', 'unsigned');
        $space->addProperty('name', 'string');
        $space->addProperty('nick', 'string', ['default' => 'nick']);

        $todo = array_keys($userTypes);
        $todo[] = 'array';

        foreach ($todo as $nick) {
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
        });

        $pool->create('first.array', ['nick' => 'qwerty']);
        $pool->create('second.constructor', ['nick' => 'asdf']);

        $changes = $pool->getChanges();
        $this->assertCount(4, $changes);
        $this->assertSame($changes[0]->space, 'first.array');
        $this->assertSame($changes[2]->space, 'second.array');
    }
}
