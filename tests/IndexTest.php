<?php

use Tarantool\Mapper\Migrations\Migrator;

class IndexTest extends PHPUnit_Framework_TestCase
{
    public function testLongIndexName()
    {
        $manager = Helper::createManager();
        $properties = ['sector', 'month', 'year', 'day', 'task_status'];
        $task = $manager->getMeta()->create('task', $properties)->addIndex($properties);
        $this->assertNotNull($task->findIndex($properties));
    }
    public function testDrop()
    {
        $manager = Helper::createManager();

        $task = $manager->getMeta()->create('task', ['sector', 'year', 'month']);
        $task->addIndex(['sector', 'year', 'month']);
        $task->dropindex(['sector', 'year', 'month']);
    }

    public function testSimple()
    {
        $manager = Helper::createManager();

        $manager->getMeta()
            ->create('unit_param', ['unit', 'param', 'value'])
            ->setPropertyType('unit', 'integer')
            ->setPropertyType('param', 'integer')
            ->setPropertyType('value', 'integer')
            ->addIndex(['unit', 'param']);

        $this->assertSame(['unit', 'param'], $manager->getMeta()->get('unit_param')->getIndex(1));

        $manager->create('unit_param', [
            'unit' => '1',
            'param' => '1',
            'value' => '11',
        ]);

        $manager->create('unit_param', [
            'unit' => '1',
            'param' => 2,
            'value' => 12,
        ]);

        $manager->create('unit_param', [
            'unit' => '2',
            'param' => '1',
            'value' => '21',
        ]);

        $manager = Helper::createManager(false);
        $unitParam = $manager->get('unit_param');
        $this->assertSame($unitParam->findOne(['param' => '2', 'unit' => '1'])->value, 12);
        $this->assertSame($unitParam->findOne(['unit' => '1', 'param' => '2'])->value, 12);
        $this->assertSame($unitParam->findOne(['unit' => '2', 'param' => 1])->value, 21);
    }

    public function testMiltiple()
    {
        $manager = Helper::createManager();

        $migrator = new Migrator();
        $migrator->registerMigration(CreatePosts::class);
        $migrator->migrate($manager);

        $manager->save($manager->get('posts')->create([
            'title' => 'a',
            'slug' => 'a',
            'body' => 'a',
            'author' => 'Dmitry Krokhin',
            'month' => 'March',
        ]));

        $manager->save($manager->get('posts')->create([
            'title' => 'b',
            'slug' => 'b',
            'body' => 'b',
            'author' => 'Dmitry Krokhin',
            'month' => 'March',
        ]));

        $manager->save($manager->get('posts')->create([
            'title' => 'c',
            'slug' => 'c',
            'body' => 'c',
            'author' => 'Dmitry Krokhin',
            'month' => 'January',
        ]));

        $this->assertCount(3, $manager->get('posts')->find([]));

        // find method
        $posts = $manager->get('posts')->find(['month' => 'March', 'author' => 'Dmitry Krokhin']);
        $this->assertCount(2, $posts);

        // swap arguments
        $posts = $manager->get('posts')->find(['author' => 'Dmitry Krokhin', 'month' => 'January']);
        $this->assertCount(1, $posts);
        $this->assertSame('c', $posts[0]->title);

        // magic methods
        $posts = $manager->get('posts')->byAuthorAndMonth('Dmitry Krokhin', 'March');
        $this->assertCount(2, $posts);

        $posts = $manager->get('posts')->byMonthAndAuthor('January', 'Dmitry Krokhin');
        $this->assertCount(1, $posts);

        // skip body
        $emptyPost = $manager->save($manager->get('posts')->create([
            'slug' => 'a-post-without-title-and-body',
            'author' => 'Dmitry Krokhin',
            'month' => 'December',
        ]));

        $posts = $manager->get('posts')->byMonthAndAuthor('January', 'Vasiliy');
        $this->assertCount(0, $posts);

        $newManager = Helper::createManager(false);
        $newEmptyPost = $newManager->get('posts')->find($emptyPost->id);
        $this->assertNull($newEmptyPost->header);
        $this->assertNull($newEmptyPost->body);
    }

    public function testPartial()
    {
        $manager = Helper::createManager();
        $manager->getMeta()
            ->create('task')
            ->addProperty('year', 'integer')
            ->addProperty('month', 'integer')
            ->addProperty('day', 'integer')
            ->addIndex(['year', 'month', 'day']);

        $manager->create('task', ['year' => 2015, 'month' => 4, 'day' => 1]);
        $manager->create('task', ['year' => 2016, 'month' => 4, 'day' => 1]);
        $manager->create('task', ['year' => 2016, 'month' => 4, 'day' => 2]);
        $manager->create('task', ['year' => 2016, 'month' => 4, 'day' => 3]);
        $manager->create('task', ['year' => 2016, 'month' => 4, 'day' => 4]);
        $manager->create('task', ['year' => 2016, 'month' => 4, 'day' => 5]);
        $manager->create('task', ['year' => 2016, 'month' => 5, 'day' => 1]);

        $this->assertCount(1, $manager->get('task', ['year' => 2015]));
        $this->assertCount(6, $manager->get('task', ['year' => 2016]));
        $this->assertCount(5, $manager->get('task', ['year' => 2016, 'month' => 4]));
        $this->assertCount(1, $manager->get('task', ['year' => 2016, 'month' => 4, 'day' => 3]));
    }
}
