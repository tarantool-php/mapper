<?php

use Tarantool\Mapper\Plugins\Reflection;
use Tarantool\Mapper\Plugins\Sequence;
use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Repository;

class ReflectionTest extends TestCase
{
    public function test()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $sequence = $mapper->addPlugin(Sequence::class);
        $mapper->addPlugin(Reflection::class)
            ->register('Entities\\Post')
            ->register('Entities\\Person')
            ->register('Repositories\\Post')
            ->migrate();

        $mapper->getPlugin(Reflection::class)->migrate();

        $nekufa = $mapper->create('person', [
            'name' => 'Dmitry.Krokhin'
        ]);

        $post = $mapper->create('post', [
            'slug' => 'test',
            'title' => 'Testing',
            'author' => $nekufa,
        ]);

        $this->assertInstanceOf('Entities\\Person', $nekufa);
        $this->assertInstanceOf('Repositories\\Post', $mapper->getSchema()->getSpace('post')->getRepository());
    }
}

