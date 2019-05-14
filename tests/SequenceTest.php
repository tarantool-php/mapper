<?php

use Tarantool\Mapper\Space;
use Tarantool\Mapper\Plugin\Sequence;

class SequenceTest extends TestCase
{
    public function testInstanceOverwriteOnPluginAdd()
    {
        $mapper = $this->createMapper();
        $plugin = $mapper->getPlugin(Sequence::class);
        $plugin2 = $mapper->getPlugin(Sequence::class);
        $this->assertSame($plugin, $plugin2);
    }

    public function testInstanceOverwriteExceptionOnPluginAdd()
    {
        $mapper = $this->createMapper();
        $mapper->getPlugin(new Sequence($mapper));
        $this->expectExceptionMessage(Sequence::class. ' is registered');
        $mapper->getPlugin(new Sequence($mapper));
    }

    public function testInitialization()
    {
        $mapper = $this->createMapper();
        $mapper->getPlugin(new Sequence($mapper));
        $this->clean($mapper);

        $this->assertCount(1, $mapper->getPlugins());

        $person = $mapper->getSchema()->createSpace('person');
        $person->addProperty('id', 'unsigned');
        $person->addProperty('email', 'string');
        $person->createIndex('id');

        $this->assertCount(0, $mapper->find('_vsequence'));

        $mapper->create('person', [1, 'nekufa@gmail.com']);
        $mapper->create('person', [2, 'petya@gmail.com']);
        $mapper->create('person', [3, 'sergey@gmail.com']);

        $this->assertCount(0, $mapper->find('_vsequence'));

        $pasha = $mapper->create('person', 'pasha');
        $this->assertSame($pasha->id, 4);

        $this->assertCount(1, $mapper->find('_vsequence'));
    }

    public function testInitializationOnSecondKeyField()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $person = $mapper->getSchema()->createSpace('person');
        $person->addProperty('idle', 'unsigned');
        $person->addProperty('id', 'unsigned');
        $person->addProperty('email', 'string');
        $person->createIndex('id');

        $mapper->create('person', [
            'id' => 1,
            'email' => 'nekufa@gmail.com'
        ]);
        $mapper->create('person', [
            'id' => 2,
            'email' => 'petya@gmail.com'
        ]);
        $mapper->create('person', [
            'id' => 3,
            'email' => 'sergey@gmail.com'
        ]);

        $mapper->getPlugin(new Sequence($mapper));
        $this->assertCount(1, $mapper->getPlugins());

        $pasha = $mapper->create('person', [
            'email' => 'pasha'
        ]);
        $this->assertSame($pasha->id, 4);
    }


    public function testCompositeKeyException()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $person = $mapper->getSchema()->createSpace('person');
        $person->addProperty('id', 'unsigned');
        $person->addProperty('company', 'unsigned');
        $person->addProperty('email', 'string');
        $person->createIndex(['id', 'company']);
        
        $this->expectExceptionMessage('Composite primary key');
        $mapper->getPlugin(new Sequence($mapper))->initializeSequence($person);
    }

    public function testPluginInstance()
    {
        $mapper = $this->createMapper();
        $mapper->getPlugin(new Sequence($mapper));
        $this->clean($mapper);

        $this->assertCount(1, $mapper->getPlugins());

        $person = $mapper->getSchema()->createSpace('person');
        $person->addProperty('id', 'unsigned');
        $person->addProperty('email', 'string');
        $person->createIndex('id');

        $nekufa = $mapper->create('person', ['email' => 'nekufa@gmail.com']);
        $this->assertSame($nekufa->id, 1);

        $rybakit = $mapper->create('person', ['email' => 'gen.work@gmail.com']);
        $this->assertSame($rybakit->id, 2);

        $this->assertCount(1, $mapper->find('_vsequence'));
    }

    public function testPluginClass()
    {
        $mapper = $this->createMapper();
        $mapper->getPlugin(Sequence::class);
        $this->clean($mapper);

        $this->assertCount(1, $mapper->getPlugins());

        $person = $mapper->getSchema()->createSpace('person');
        $person->addProperty('id', 'unsigned');
        $person->addProperty('email', 'string');
        $person->createIndex('id');

        $nekufa = $mapper->create('person', ['email' => 'nekufa@gmail.com']);
        $this->assertSame($nekufa->id, 1);

        $rybakit = $mapper->create('person', ['email' => 'gen.work@gmail.com']);
        $this->assertSame($rybakit->id, 2);
    }
}
