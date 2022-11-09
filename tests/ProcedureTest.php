<?php

namespace Tarantool\Mapper\Tests;

use Exception;
use Procedure\Greet;
use Procedure\Info;
use Tarantool\Client\Handler\Handler;
use Tarantool\Client\Middleware\Middleware;
use Tarantool\Client\Request\Request;
use Tarantool\Client\Response;
use Tarantool\Mapper\Plugin\Procedure;

class ProcedureTest extends TestCase
{
    public function testOptionalRegistration()
    {
        $spy = new class implements Middleware {
            public array $requests = [];
            public function process(Request $request, Handler $handler): Response
            {
                $this->requests[] = $request;
                return $handler->handle($request);
            }
        };

        $mapper = $this->createMapper($spy);
        $this->clean($mapper);

        $plugin = $mapper->getPlugin(Procedure::class);
        $greet = $plugin->get(Greet::class);
        $this->assertSame($greet('nekufa'), 'Hello, nekufa!');

        $spy->requests = [];
        $this->assertCount(0, $spy->requests);

        $greet = $plugin->get(Greet::class);
        $this->assertSame($greet('nekufa'), 'Hello, nekufa!');

        // single request per procedure call
        $this->assertCount(1, $spy->requests);

        $spy->requests = [];
    }

    public function testTypeCheck()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $procedure = $mapper->getPlugin(Procedure::class);

        $this->expectException(Exception::class);
        $procedure->register(__CLASS__);
    }

    public function testRegistration()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $procedure = $mapper->getPlugin(Procedure::class);
        $procedure->register(Greet::class);

        $this->assertTrue($procedure->isRegistered(Greet::class));
        $this->assertFalse($procedure->isRegistered(__CLASS__));

        $greet = $procedure->get(Greet::class);
        $this->assertSame($greet('nekufa'), 'Hello, nekufa!');

        $mapper->getClient()->evaluate("box.schema.func.drop('greet')");
        $mapper->getClient()->evaluate("_G.greet = nil");
        $this->assertSame($greet('nekufa'), 'Hello, nekufa!');
    }

    public function testNoRegistration()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $procedure = $mapper->getPlugin(Procedure::class);
        $greet = $procedure->get(Greet::class);
        $this->assertSame($greet('nekufa'), 'Hello, nekufa!');
    }

    public function testMapping()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $procedure = $mapper->getPlugin(Procedure::class);
        $collect = $procedure->get(Info::class);

        $result = $collect();

        foreach ($collect->getMapping() as $property) {
            $this->assertArrayHasKey($property, $result);
        }
    }
}
