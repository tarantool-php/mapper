<?php

use Tarantool\Mapper\Entities\Entity;

class Post extends Entity
{
}

class EntityTest extends PHPUnit_Framework_TestCase
{
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

    public function testMagicMethods()
    {
        $manager = Helper::createManager();
        $manager->getMeta()->create('person', ['name']);

        $person = $manager->create('person');
        $person->setName('dmitry');

        $manager->save($person);
    }

    public function testIdUpdate()
    {
        $post = new Entity([
            'title' => 'testing',
        ]);
        $this->assertNull($post->getId());
        $post->setId(1);
        $this->setExpectedException(Exception::class);
        $post->setId(2);
    }
}
