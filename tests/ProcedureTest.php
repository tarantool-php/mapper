<?php

use Tarantool\Mapper\Plugin\Procedure;
use Procedure\Greet;

class ProcedureTest extends TestCase
{
    public function testTypeCheck()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $procedure = $mapper->addPlugin(Procedure::class);

        $this->expectException(Exception::class);
        $procedure->register(__CLASS__);
    }

    public function testRegistration()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $procedure = $mapper->addPlugin(Procedure::class);
        $procedure->register(Greet::class);

        $this->assertTrue($procedure->isRegistered(Greet::class));
        $this->assertFalse($procedure->isRegistered(__CLASS__));

        $greet = $procedure->get(Greet::class);
        $this->assertSame($greet('nekufa'), 'Hello, nekufa!');
    }

    public function testNoRegistration()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $procedure = $mapper->addPlugin(Procedure::class);
        $greet = $procedure->get(Greet::class);
        $this->assertSame($greet('nekufa'), 'Hello, nekufa!');
    }
}
