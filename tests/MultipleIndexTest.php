<?php

use Tarantool\Mapper\Migrations\Migrator;

class MultipleIndexTest extends PHPUnit_Framework_TestCase
{
    public function testUsage()
    {
        $manager = Helper::createManager();

        $migrator = new Migrator();
        $migrator->registerMigration(CreatePosts::class);
        $migrator->migrate($manager);

        $manager->save($manager->get('posts')->make([
            'title' => 'a',
            'slug' => 'a',
            'body' => 'a',
            'author' => 'Dmitry Krokhin',
            'month' => 'March',
        ]));

        $manager->save($manager->get('posts')->make([
            'title' => 'b',
            'slug' => 'b',
            'body' => 'b',
            'author' => 'Dmitry Krokhin',
            'month' => 'March',
        ]));

        $manager->save($manager->get('posts')->make([
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

        $posts = $manager->get('posts')->byMonthAndAuthor('January', 'Vasiliy');
        $this->assertCount(0, $posts);
    }
}
