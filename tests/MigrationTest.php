<?php

use Tarantool\Mapper\Migrations\Migrator;

class MigrationTest extends PHPUnit_Framework_TestCase
{
    public function testUsage()
    {
        $manager = Helper::createManager();

        $migrator = new Migrator();
        $migrator->registerMigration(CreatePosts::class);
        $migrator->migrate($manager);

        $post = $manager->get('posts')->make([
            'title' => 'hello world',
            'slug' => 'hello-world',
            'body' => 'This is hello world post',
            'author' => 'Dmitry Krokhin',
            'month' => 'March',
        ]);

        $manager->save($post);

        $this->assertNotNull($post->id);
    }
}
