<?php

namespace Tarantool\Mapper\Tests;

use Entity\Type\Complex;
use Entity\Type\Simple;
use Exception;
use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Plugin\Annotation;
use Tarantool\Mapper\Plugin\Sequence;
use Tarantool\Mapper\Plugin\UserClasses;
use Tarantool\Mapper\Repository;

class AnnotationTest extends TestCase
{
    public function testEntityTriggers()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);
        $mapper->getPlugin(Sequence::class);

        $mapper->getPlugin(Annotation::class)
            ->register('Entity\\Person')
            ->migrate();

        $person = $mapper->getRepository('person')->create([
            'name' => 'bazyabushka',
        ]);

        $this->assertNull($person->fullName);
        $this->assertNull($person->id);

        $person->save();

        $this->assertNotNull($person->id);
        $this->assertSame($person->fullName, 'bazyabushka!@');
    }

    public function testMixedClassMap()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);
        $mapper->getPlugin(Sequence::class);

        $mapper->getPlugin(Annotation::class)
            ->register('Entity\\Post')
            ->register('Repository\\Post')
            ->migrate();

        $mapper->getPlugin(UserClasses::class)
            ->mapRepository('post', 'Repository\\Post');

        $hello = $mapper->create('post', ['title' => 'hello-world', 'slug' => 'first']);
        $this->assertInstanceOf('Entity\\Post', $hello);
    }

    public function testBoolean()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);
        $mapper->getPlugin(Sequence::class);

        $annotation = $mapper->getPlugin(Annotation::class);
        $annotation->register('Entity\\Paycode');
        $annotation->migrate();
        $active = $mapper->getSchema()->getSpace('paycode')->getProperty('active');
        $this->assertSame($active->type, 'boolean');

        $paycode = $mapper->create('paycode', [
            'active' => 1,
        ]);
        $this->assertNotNull($paycode->id);
        $this->assertTrue($paycode->active);

        $paycode = $mapper->create('paycode', [
            'active' => 0,
        ]);
        $this->assertNotNull($paycode->id);
        $this->assertFalse($paycode->active);
    }

    public function testInheritance()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);
        $mapper->getPlugin(Sequence::class);

        $annotation = $mapper->getPlugin(Annotation::class);
        $annotation->register('Entity\\LegacyType');
        $annotation->register('Entity\\Type');
        $annotation->register('Entity\\Type\\Simple');
        $annotation->register('Entity\\Type\\Complex');
        $annotation->migrate();

        // no error on second call
        $annotation->migrate();

        $schema = $mapper->getSchema();
        $this->assertTrue($schema->hasSpace('type'));
        $this->assertTrue(!$schema->hasSpace('type_simple'), 'no space for simple type');
        $this->assertTrue(!$schema->hasSpace('simple'), 'no space for simple type');

        $this->assertCount(2, $mapper->find('type'));

        $complex = $mapper->findOrFail('type', [
            'class' => Complex::class
        ]);

        $this->assertInstanceOf(Complex::class, $complex);

        $simple = $mapper->findOrFail('type', [
            'class' => Simple::class,
        ]);
        $this->assertSame($simple->name, 'simplest!');
        $this->assertInstanceOf(Simple::class, $simple);

        $types = $mapper->find('type');
        $this->assertCount(2, $types);

        $legacy = $mapper->create('legacy_type', [
            'class' => 'blablabla'
        ]);
        $this->assertSame($legacy->class, "blablabla");
        $this->assertInstanceOf(Entity::class, $legacy);
    }

    public function testCamelCased()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);
        $mapper->getPlugin(Sequence::class);

        $annotation = $mapper->getPlugin(Annotation::class);
        $annotation->register('Entity\\CamelParent');
        $annotation->register('Entity\\CamelChild');
        $annotation->register('Repository\\CamelChild');
        $annotation->migrate();
        $annotation->migrate();

        $parent = $mapper->create('camel_parent', ['name' => 'p1']);
        $child = $mapper->create('camel_child', ['camelParent' => $parent, 'name' => 'c1']);

        $repository = $mapper->getRepository('camel_child');
        $this->assertInstanceOf('Repository\\CamelChild', $repository);
        $this->assertSame($repository->getSpace()->engine, 'vinyl');

        $this->assertEquals($child->getCamelParent(), $parent);
        $this->assertEquals($parent->getCamelChildCollection()[0]->toArray(), $child->toArray());
    }

    public function testAnnotationAddProperty()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $paycodeSpace = $mapper->getSchema()
            ->createSpace('paycode', [
                'id' => 'unsigned',
                'name' => 'string',
            ])
            ->addIndex('id');

        $paycode = $mapper->create('paycode', [
            'id' => 1,
            'name' => 'tester'
        ]);
        $this->assertObjectNotHasProperty('factor', $paycode);

        $annotation = $mapper->getPlugin(Annotation::class);
        $annotation->register('Entity\\Paycode');
        $annotation->migrate();

        $this->assertTrue($paycodeSpace->getProperty('factor')->isNullable);
        $this->assertNotNull($paycodeSpace->getProperty('factor')->defaultValue);

        $paycode = $mapper->create('paycode', [
            'id' => 2,
            'name' => 'tester2'
        ]);

        $this->assertSame($paycode->factor, 0.5);
    }
    public function testFloatType()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->getPlugin(Sequence::class);

        $annotation = $mapper->getPlugin(Annotation::class);
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

        $mapper->getPlugin(Sequence::class);
        $mapper->getSchema()
            ->createSpace('invalid_index', [
                'id' => 'unsigned'
            ])
            ->addIndex('id');

        $i = $mapper->create('invalid_index', ['id' => 1]);

        $annotation = $mapper->getPlugin(Annotation::class);
        // no name property found
        $annotation->register('Repository\\InvalidIndex');

        $this->expectException(Exception::class);
        $annotation->migrate();
    }

    public function testTarantoolTypeHint()
    {
        $mapper = $this->createMapper();
        $mapper->getPlugin(Sequence::class);

        $annotation = $mapper->getPlugin(Annotation::class);
        $annotation->register('Entity\\Address');
        $annotation->migrate();

        $space = $mapper->findOne('_space', ['name' => 'address']);

        // required tag for address field
        $this->assertSame(false, $space->format[3]['is_nullable']);

        // house property
        // tarantool type hint (allow negative values)
        $this->assertSame('integer', $space->format[4]['type']);
        // property is required
        $this->assertSame(false, $space->format[4]['is_nullable']);

        // house property
        // tarantool type hint (allow negative values)
        $this->assertSame('integer', $space->format[5]['type']);
        // property is not required
        $this->assertSame(true, $space->format[5]['is_nullable']);

        // tarantool type hint for map
        $this->assertSame('map', $space->format[6]['type']);

        $address = $mapper->create('address', []);
        $this->assertSame($address->street, "");
        $this->assertSame($address->house, 0);
        $this->assertNull($address->flat);
    }

    public function testCorrectDefinition()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->getPlugin(Sequence::class);

        $annotation = $mapper->getPlugin(Annotation::class)
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

        $nekufa = $mapper->findOrCreate('person', [
            'name' => 'Dmitry.Krokhin',
        ]);

        $post = $mapper->create('post', [
            'slug' => 'test',
            'title' => 'Testing',
            'author' => $nekufa,
        ]);

        $this->assertInstanceOf('Entity\\Person', $nekufa);
        $this->assertInstanceOf('Repository\\Post', $mapper->getSchema()->getSpace('post')->getRepository());

        $this->assertEquals($post->getAuthor(), $nekufa);
        $this->assertSame($nekufa->fullName, 'Dmitry.Krokhin!');

        $meta = $mapper->getMeta();

        $newMapper = $this->createMapper();
        $newMapper->setMeta($meta);
        $newPost = $newMapper->findOne('post', $post->id);
        $this->assertSame($newPost->author, $nekufa->id);
        $this->assertSame($newPost->getAuthor()->id, $nekufa->id);
    }

    public function testSpaceFlags()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->getPlugin(Sequence::class);

        $mapper->getPlugin(Annotation::class)
            ->register('Entity\\Person')
            ->register('Repository\\Person')
            ->migrate();

        $person = $mapper->findOrFail('_space', ['name' => 'person']);
        $this->assertSame($person->flags, ['temporary' => true]);

        $mapper->getPlugin(Annotation::class)
            ->register('Entity\\Post')
            ->register('Repository\\Post')
            ->migrate();

        $post = $mapper->findOrFail('_space', ['name' => 'post']);
        $this->assertSame($post->flags, ['group_id' => 1]);
    }
}
