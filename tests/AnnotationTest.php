<?php

use Tarantool\Mapper\Plugin\Annotation;
use Tarantool\Mapper\Plugin\Sequence;
use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Repository;

class AnnotationTest extends TestCase
{
    public function testFloatType()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->addPlugin(Sequence::class);
        $annotation = $mapper->addPlugin(Annotation::class);
        $annotation->register('Entity\\Paycode');
        $annotation->migrate();

        $paycode = $mapper->create('paycode', ['name' => 'overtime', 'factor' => "1.2"]);
        $this->assertSame($paycode->factor, 1.2);

        $mapper = $this->createMapper();
        $anotherInstance = $mapper->findOne('paycode');
        $this->assertSame($anotherInstance->factor, $paycode->factor);
    }

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

        $this->expectException(Exception::class);
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

        // no duplicate exceptions should be thrown
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

        $this->assertSame($post->getAuthor(), $nekufa);
        $this->assertSame($nekufa->name, 'Dmitry.Krokhin!');

        $meta = $mapper->getMeta();

        $newMapper = $this->createMapper();
        $newMapper->setMeta($meta);
        $newPost = $newMapper->findOne('post', $post->id);
        $this->assertSame($newPost->author, $nekufa->id);
        $this->assertSame($newPost->getAuthor()->id, $nekufa->id);
    }
}
