<?php

use Tarantool\Mapper\Plugin\Annotation;
use Tarantool\Mapper\Plugin\Sequence;
use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Repository;

class AnnotationTest extends TestCase
{
    public function test()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->addPlugin(Sequence::class);

        $annotation = $mapper->addPlugin(Annotation::class)
            ->setRepositoryPostfix('Repository')
            ->register('Entities\\Post')
            ->register('Entities\\Person')
            ->register('Repositories\\PostRepository');

        $annotation->migrate();
        $annotation->migrate();

        $this->assertSame($annotation->getRepositoryMapping(), [
            'post' => 'Repositories\\PostRepository',
        ]);

        $this->assertEquals($annotation->getEntityMapping(), [
            'person' => 'Entities\\Person',
            'post'   => 'Entities\\Post',
        ]);

        $nekufa = $mapper->create('person', [
            'name' => 'Dmitry.Krokhin'
        ]);

        $post = $mapper->create('post', [
            'slug' => 'test',
            'title' => 'Testing',
            'author' => $nekufa,
        ]);

        $this->assertInstanceOf('Entities\\Person', $nekufa);
        $this->assertInstanceOf('Repositories\\PostRepository', $mapper->getSchema()->getSpace('post')->getRepository());
    }
}

