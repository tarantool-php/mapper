<?php

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
    }

    public function testDynamic()
    {
        $pool = new Pool();
        $pool->registerResolver(function() {
            return $this->createMapper();
        });

        $this->assertNotNull($pool->get('mapper1'));
        $this->assertSame($pool->get('mapper1'), $pool->get('mapper1'));
        $this->assertNotSame($pool->get('mapper1'), $pool->get('mapper2'));
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
        $pool->registerResolver(function($service) {
            return $service == 'tester' ? $this->createMapper() : null;
        });

        $this->assertNotNull($pool->get('tester'));

        $this->expectException(Exception::class);
        $pool->get('invalid');
    }
}
