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
            ->register('Entity\\Post')
            ->register('Entity\\Person')
            ->register('Repository\\PostRepository');

        $annotation->migrate();
        $annotation->migrate();

        $this->assertSame($annotation->getRepositoryMapping(), [
            'post' => 'Repository\\PostRepository',
        ]);

        $this->assertEquals($annotation->getEntityMapping(), [
            'person' => 'Entity\\Person',
            'post'   => 'Entity\\Post',
        ]);

        $nekufa = $mapper->create('person', [
            'name' => 'Dmitry.Krokhin'
        ]);

        $post = $mapper->create('post', [
            'slug' => 'test',
            'title' => 'Testing',
            'author' => $nekufa,
        ]);

        $this->assertInstanceOf('Entity\\Person', $nekufa);
        $this->assertInstanceOf('Repository\\PostRepository', $mapper->getSchema()->getSpace('post')->getRepository());
    }
}

