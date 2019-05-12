<?php

use Tarantool\Mapper\Client;
use Tarantool\Mapper\Mapper;
use Tarantool\Mapper\Schema;
use Tarantool\Mapper\Plugin\Sequence;
use Tarantool\Mapper\Middleware\DebuggerMiddleware;
use Tarantool\Client\Request\InsertRequest;

class MetaTest extends TestCase
{
    public function testCaching()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->setClient($mapper->getClient()->withMiddleware($debugger = new DebuggerMiddleware));

        $mapper->getSchema()
            ->createSpace('tester', [
                'a' => 'unsigned',
                'b' => 'unsigned',
            ])
            ->addIndex(['a', 'b']);

        $mapper->getSchema()
            ->createSpace('tester2', [
                'a' => 'unsigned',
                'b' => 'unsigned',
            ])
            ->addIndex(['a', 'b']);

        $this->assertNotCount(0, $debugger->getLog());

        $meta = $mapper->getMeta();

        $mapper2 = $this->createMapper();
        $mapper2->setClient($mapper2->getClient()->withMiddleware($debugger2 = new DebuggerMiddleware));
        $this->assertEquals($meta, $mapper2->getMeta());

        $mapper3 = $this->createMapper();
        $mapper3->setClient($mapper3->getClient()->withMiddleware($debugger3 = new DebuggerMiddleware));
        $mapper3->setMeta($meta);

        $mapper3->getSchema()->getSpace('tester')->getFormat();
        $mapper3->getSchema()->getSpace('tester2')->getFormat();
        $this->assertCount(0, $debugger3->getLog());
    }
}
