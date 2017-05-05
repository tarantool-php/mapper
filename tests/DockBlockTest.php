<?php

use Tarantool\Mapper\Plugins\DocBlock;
use Tarantool\Mapper\Plugins\Sequence;
use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Repository;

class DocBlockTest extends TestCase
{
    public function test()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $sequence = $mapper->addPlugin(Sequence::class);
        $mapper->addPlugin(DocBlock::class)
            ->register('Entities\\Post')
            ->register('Entities\\Person')
            ->register('Repositories\\Post')
            ->migrate();

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

