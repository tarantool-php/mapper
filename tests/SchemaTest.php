<?php

use Tarantool\Mapper\Entity;

class Post extends Entity
{
}

class SchemaTest extends PHPUnit_Framework_TestCase
{
    public function testPropertiesCheck()
    {
        $manager = Helper::createManager();

        $manager->getMeta()->create('person', ['name']);

        // create entity
        try {
            $manager->create('person', [
                'name' => 'dmitry',
                'email' => 'nekufa@gmail.com',
            ]);
            $this->assertNull('no exceptions when setting incorrect property');
        } catch (Exception $e) {
            $this->assertSame($e->getMessage(), 'Unknown property person.email');
        }

        // update entity
        try {
            $person = $manager->create('person', [
                'name' => 'dmitry',
            ]);
            $person->email = 'nekufa@gmail.com';
            $manager->save($person);
            $this->assertNull('no exception when adding incorrect property');
        } catch (Exception $e) {
            $this->assertSame($e->getMessage(), 'Unknown property person.email');
        }
    }

    public function testEntityClass()
    {
        $manager = Helper::createManager();
        $manager->getMeta()->create('person', ['name']);

        $instance = $manager->create('person', 'dmitry');
        $this->assertInstanceOf(Entity::class, $instance);

        $type = $manager->getMeta()->create('post', ['title']);
        $type->setEntityClass(Post::class);

        $instance = $manager->create('post', 'user class for entity implemented');
        $this->assertInstanceOf(Post::class, $instance);
    }

    public function testPropertyRename()
    {
        $manager = Helper::createManager();
        $post = $manager->getMeta()->create('post', ['date', 'status', 'label']);
        $post->addIndex(['date', 'status'], ['unique' => false]);
        $this->assertNotNull($manager->create('post', ['date' => 20160513, 'status' => 1]));
        $post->renameProperty('status', 'post_status');
        $this->assertSame($post->getProperties(), ['id', 'date', 'post_status', 'label']);
        $this->assertSame(1, $post->findIndex(['post_status', 'date']));
        $this->assertSame('string', $post->getPropertyType('post_status'));
        $this->assertNotNull($manager->create('post', ['date' => 20160513, 'label' => 'oops']));
        $this->assertNotNull($manager->create('post', ['date' => 20160513]));

        $manager = Helper::createManager(false);
        $post = $manager->getMeta()->get('post');
        $this->assertSame($post->getProperties(), ['id', 'date', 'post_status', 'label']);

        $this->setExpectedException(Exception::class);
        $post->renameProperty('unknown_property', 'new_name');
    }

    public function testNewIndexProvidesCacheClean()
    {
        $manager = Helper::createManager();
        $post = $manager->getMeta()->create('post', ['date', 'status', 'label']);
        $post->addIndex(['date', 'status'], ['unique' => false]);
        $this->assertNotNull($manager->create('post', ['date' => 1, 'status' => 2, 'label' => 3]));
        $post->addIndex(['status', 'label'], ['unique' => false]);
        $this->assertNotNull($manager->create('post', ['date' => 1, 'status' => 2]));
    }

    public function testPropertyFromMultiIndexShouldBeRemovedWhenRemoveWholeType()
    {
        $manager = Helper::createManager();
        $post = $manager->getMeta()->create('post', ['date', 'status']);
        $post->addIndex(['date', 'status']);
        $manager->getMeta()->remove('post');
    }

    public function testPropertyFromMultiIndexShouldNotBeRemoved()
    {
        $manager = Helper::createManager();
        $post = $manager->getMeta()->create('post', ['date', 'status']);
        $post->addIndex(['date', 'status']);
        $this->setExpectedException(Exception::class);
        $post->removeProperty('status');
    }

    public function testSpaceName()
    {
        $manager = Helper::createManager();
        $this->assertSame('_space', $manager->getSchema()->getSpaceName(280));
        $this->assertFalse($manager->getMeta()->has('_space'));

        // manager updates space names online
        $newManager = Helper::createManager(false);
        $newManager->getMeta()->create('item', ['name']);

        $spaceId = $manager->getClient()->getSpace('item')->getId();
        $this->assertSame('item', $manager->getSchema()->getSpaceName($spaceId));
    }

    public function testTypeExistQuery()
    {
        $manager = Helper::createManager();
        $this->assertFalse($manager->getMeta()->has('person'));
        $manager->getMeta()->create('person');
        $this->assertTrue($manager->getMeta()->has('person'));

        $anotherManager = Helper::createManager(false);
        $this->assertTrue($anotherManager->getMeta()->has('person'));
    }

    public function testConventionOverride()
    {
        $meta = Helper::createManager()->getMeta();
        $this->assertNotNull($meta->getConvention());

        $convention = new Tarantool\Mapper\Schema\Convention();
        $meta->setConvention($convention);
        $this->assertSame($meta->getConvention(), $convention);

        $this->assertSame([1], $convention->encode('array', [1]));
    }
}
