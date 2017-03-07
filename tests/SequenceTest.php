<?php

use Tarantool\Mapper\Space;
use Tarantool\Mapper\Plugins\Sequence;

class SequenceTest extends TestCase
{
    public function test()
    {
        $mapper = $this->createMapper();
        $mapper->addPlugin(Sequence::class);
        $this->clean($mapper);

        $this->assertCount(1, $mapper->getPlugins());

        $person = $mapper->getSchema()->createSpace('person');
        $person->addProperty('id', 'unsigned');
        $person->addProperty('email', 'str');
        $person->createIndex('id');

        $nekufa = $mapper->create('person', ['email' => 'nekufa@gmail.com']);
        $this->assertSame($nekufa->id, 1);

        $rybakit = $mapper->create('person', ['email' => 'gen.work@gmail.com']);
        $this->assertSame($rybakit->id, 2);
    }
}