<?php

use Tarantool\Mapper\Space;
use Tarantool\Mapper\Plugin\Spy;
use Tarantool\Mapper\Plugin\Sequence;

class SpyTest extends TestCase
{
    public function test()
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

        $mapper->getPlugin(Spy::class);
        $this->assertCount(2, $mapper->getPlugins());

        $rybakit = $mapper->create('person', ['email' => 'gen.work@gmail.com']);
        $nekufa->email = 'dmitry.krokhin@basis.company';
        $mapper->save($nekufa);

        // there should be 2 changes - 1 create, 1 update

        $spy = $mapper->getPlugin(Spy::class);
        $this->assertInstanceOf(Spy::class, $spy);

        $this->assertTrue($spy->hasChanges());

        $changes = $spy->getChanges();
        $this->assertCount(1, $changes->created);
        $this->assertCount(1, $changes->updated);
        $this->assertCount(0, $changes->removed);

        $mapper->remove($rybakit);
        $mapper->remove($nekufa);

        $changes = $spy->getChanges();
        $this->assertCount(0, $changes->created);
        $this->assertCount(0, $changes->updated);
        $this->assertCount(1, $changes->removed);
        $this->assertSame($changes->removed['person'][0]->id, $nekufa->id);
        $this->assertTrue($spy->hasChanges());

        $spy->reset();

        $changes = $spy->getChanges();
        $this->assertFalse($spy->hasChanges());
        $this->assertCount(0, $changes->created);
        $this->assertCount(0, $changes->updated);
        $this->assertCount(0, $changes->removed);

        $vasya = $mapper->create('person', ['email' => 'vasya@mail.ru']);

        $changes = $spy->getChanges();
        $this->assertTrue($spy->hasChanges());
        $this->assertCount(1, $changes->created);
        $this->assertCount(0, $changes->updated);
        $this->assertCount(0, $changes->removed);

        $this->assertSame([$vasya], $changes->created['person']);

        $vasya->email = 'vasya@ya.ru';
        $mapper->save($vasya);

        $changes = $spy->getChanges();
        $this->assertTrue($spy->hasChanges());
        $this->assertCount(1, $changes->created);
        $this->assertCount(0, $changes->updated);
        $this->assertCount(0, $changes->removed);
        $this->assertSame([$vasya], array_values($changes->created['person']));

        $mapper->remove($vasya);
        $changes = $spy->getChanges();
        $this->assertFalse($spy->hasChanges());
        $this->assertCount(0, $changes->created);
        $this->assertCount(0, $changes->updated);
        $this->assertCount(0, $changes->removed);

        $vasya = $mapper->create('person', ['email' => 'vasya@mail.ru']);
        $spy->reset();

        $vasya->email = 'vasya@gmail.com';
        $mapper->save($vasya);

        $changes = $spy->getChanges();
        $this->assertTrue($spy->hasChanges());
        $this->assertCount(0, $changes->created);
        $this->assertCount(1, $changes->updated);
        $this->assertCount(0, $changes->removed);

        $mapper->remove($vasya);

        $changes = $spy->getChanges();
        $this->assertTrue($spy->hasChanges());
        $this->assertCount(0, $changes->created);
        $this->assertCount(0, $changes->updated);
        $this->assertCount(1, $changes->removed);
    }
}
