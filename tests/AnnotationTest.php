<?php

use Tarantool\Mapper\Plugins\Annotation;
use Tarantool\Mapper\Plugins\Sequence;
use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Repository;

class AnnotationTest extends TestCase
{
    public function test()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $sequence = $mapper->addPlugin(Sequence::class);
        $mapper->addPlugin(Annotation::class)
            ->register('Entities\\Post')
            ->register('Entities\\Person')
            ->register('Repositories\\Post')
            ->migrate();

        $mapper->getPlugin(Annotation::class)->migrate();

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

