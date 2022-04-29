<?php

namespace Tarantool\Mapper\Tests;

use Exception;
use Tarantool\Mapper\Pool;

class PoolTest extends TestCase
{
    public function test()
    {
        $mapper = $this->createMapper();

        $pool = new Pool();
        $pool->register('test', function () use ($mapper) {
            return $mapper;
        });

        $this->assertSame($pool->get('test'), $mapper);

        $mapper2 = $this->createMapper();
        $pool->register('test2', $mapper2);
        $this->assertSame($pool->get('test2'), $mapper2);
        $this->assertNotSame($pool->get('test2'), $mapper);

        $this->assertCount(2, $pool->getMappers());

        $pool->drop('test');
        $this->assertCount(1, $pool->getMappers());
    }

    public function testDynamic()
    {
        $this->clean($this->createMapper());

        $pool = new Pool();
        $pool->registerResolver(function () {
            return $this->createMapper();
        });

        $this->assertNotNull($pool->get('mapper1'));
        $this->assertSame($pool->get('mapper1'), $pool->getMapper('mapper1'));
        $this->assertNotSame($pool->get('mapper1'), $pool->get('mapper2'));

        $this->assertSame(
            $pool->get('mapper1')->getRepository('_space'),
            $pool->getRepository('mapper1._space')
        );

        $this->assertNotCount(0, $pool->find('mapper1._space'));
        $this->assertNotNull($pool->findOne('mapper1._space'));
        $this->assertNotNull($pool->findOrFail('mapper1._space'));

        $tester = $pool->getMapper('tester');

        $tester->getSchema()
            ->createSpace('post', [
                'id' => 'integer',
                'nick' => 'string',
            ])
            ->addIndex('id');

        $instance = $pool->findOrCreate('tester.post', [
            'id' => 1,
        ]);

        $this->assertNotNull($instance);
        $this->assertEquals($instance, $pool->getMapper('tester')->findOne('post', ['id' => 1]));
    }

    public function testInvalidRegistration()
    {
        $pool = new Pool();
        $this->expectException(Exception::class);
        $pool->register('test', 'string');
    }

    public function testInvalidRetrieve()
    {
        $pool = new Pool();
        $this->expectException(Exception::class);
        $pool->get('test');
    }

    public function testInvalidDynamicRetrieve()
    {
        $pool = new Pool();
        $pool->registerResolver(function ($service) {
            return $service == 'tester' ? $this->createMapper() : null;
        });

        $this->assertNotNull($pool->get('tester'));

        $this->expectException(Exception::class);
        $pool->get('invalid');
    }
}
