<?php

use Tarantool\Mapper\Space;
use Tarantool\Mapper\Plugin\Sequence;

class SequenceTest extends TestCase
{
    public function testInitialization()
    {
        $mapper = $this->createMapper();
        $mapper->addPlugin(new Sequence($mapper));
        $this->clean($mapper);

        $this->assertCount(1, $mapper->getPlugins());

        $person = $mapper->getSchema()->createSpace('person');
        $person->addProperty('id', 'unsigned');
        $person->addProperty('email', 'str');
        $person->createIndex('id');

        $mapper->create('person', [1, 'nekufa@gmail.com']);
        $mapper->create('person', [2, 'petya@gmail.com']);
        $mapper->create('person', [3, 'sergey@gmail.com']);

        $pasha = $mapper->create('person', 'pasha');
        $this->assertSame($pasha->id, 4);
    }
    public function testPluginInstance()
    {
        $mapper = $this->createMapper();
        $mapper->addPlugin(new Sequence($mapper));
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

        $this->assertCount(1, $mapper->find('sequence'));
    }

    public function testPluginClass()
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
