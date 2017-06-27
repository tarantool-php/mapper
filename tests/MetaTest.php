<?php

use Tarantool\Mapper\Client;
use Tarantool\Mapper\Mapper;
use Tarantool\Mapper\Schema;
use Tarantool\Mapper\Plugin\Sequence;
use Tarantool\Client\Request\InsertRequest;

class MetaTest extends TestCase
{
    public function testCaching()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->getClient()->setLogging(true);

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

        $this->assertCount(10, $mapper->getClient()->getLog());

        $meta = $mapper->getMeta();

        $mapper2 = $this->createMapper();
        $mapper2->getClient()->setLogging(true);
        $this->assertEquals($meta, $mapper2->getMeta());

        $mapper3 = $this->createMapper();
        $mapper3->getClient()->setLogging(true);
        $mapper3->setMeta($meta);

        $mapper3->getSchema()->getSpace('tester')->getFormat();
        $mapper3->getSchema()->getSpace('tester2')->getFormat();
        $this->assertCount(0, $mapper3->getClient()->getLog());
    }
}
