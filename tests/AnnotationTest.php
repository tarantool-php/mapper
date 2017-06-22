<?php

use Tarantool\Mapper\Plugin\Annotation;
use Tarantool\Mapper\Plugin\Sequence;
use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Repository;

class AnnotationTest extends TestCase
{
    public function testInvalidIndexMessage()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->addPlugin(Sequence::class);
        $mapper->getSchema()
            ->createSpace('invalid_index', [
                'id' => 'unsigned'
            ])
            ->addIndex('id');

        $i = $mapper->create('invalid_index', ['id' => 1]);

        $annotation = $mapper->addPlugin(Annotation::class);
        $annotation->register('Entity\\InvalidIndex');
        $annotation->register('Repository\\InvalidIndex');

        $this->expectExceptionMessage('Failed to add index ["name"]');
        $annotation->migrate();
    }

    public function testCorrectDefinition()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->addPlugin(Sequence::class);

        $annotation = $mapper->addPlugin(Annotation::class)
            ->register('Entity\\Post')
            ->register('Entity\\Person')
            ->register('Repository\\Post')
            ->register('Repository\\Person');

        $this->assertSame('post', $annotation->getRepositorySpaceName('Repository\\Post'));

        $this->assertEquals($annotation->getRepositoryMapping(), [
            'person' => 'Repository\\Person',
            'post'   => 'Repository\\Post',
        ]);

        $this->assertEquals($annotation->getEntityMapping(), [
            'person' => 'Entity\\Person',
            'post'   => 'Entity\\Post',
        ]);

        $annotation->migrate();
        $annotation->migrate();

        $this->assertSame('post', $annotation->getRepositorySpaceName('Repository\\Post'));

        $this->assertEquals($annotation->getRepositoryMapping(), [
            'person' => 'Repository\\Person',
            'post'   => 'Repository\\Post',
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
        $this->assertInstanceOf('Repository\\Post', $mapper->getSchema()->getSpace('post')->getRepository());

        $this->assertSame($nekufa->name, 'Dmitry.Krokhin!');
    }
}
