<?php

use Tarantool\Mapper\Plugin\Procedure;
use Procedure\Greet;
use Procedure\Info;

class ProcedureTest extends TestCase
{
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
