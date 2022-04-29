<?php

namespace Tarantool\Mapper\Tests;

use Tarantool\Client\Middleware\LoggingMiddleware;
use Tarantool\Client\Request\InsertRequest;
use Tarantool\Mapper\Client;
use Tarantool\Mapper\Mapper;
use Tarantool\Mapper\Plugin\Sequence;
use Tarantool\Mapper\Schema;

class MetaTest extends TestCase
{
    public function testCaching()
    {
        $logger = new Logger();
        $mapper = $this->createMapper(new LoggingMiddleware($logger));
        $this->clean($mapper);

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

        $this->assertNotCount(0, $logger->getLog());

        $meta = $mapper->getMeta();

        $logger2 = new Logger();
        $mapper2 = $this->createMapper(new LoggingMiddleware($logger2));

        foreach ($meta as $spaceMeta) {
            $this->assertArrayHasKey('indexes', $spaceMeta);
        }

        $this->assertEquals($meta, $mapper2->getMeta());

        $logger3 = new Logger();
        $mapper3 = $this->createMapper(new LoggingMiddleware($logger3));
        $logger3->flush();
        $mapper3->setMeta($meta);

        $mapper3->getSchema()->getSpace('tester')->getProperties();
        $mapper3->getSchema()->getSpace('tester2')->getProperties();
        $this->assertCount(0, $logger3->getLog());
    }
}
