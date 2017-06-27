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

        $mapper = $this->createMapper();
        $mapper->getClient()->setLogging(true);
        $mapper->setMeta($meta);

        $mapper->getSchema()->getSpace('tester')->getFormat();
        $mapper->getSchema()->getSpace('tester2')->getFormat();
        $this->assertCount(0, $mapper->getClient()->getLog());
    }
}
