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
}
