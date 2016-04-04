<?php

use Tarantool\Mapper\Migrations\Migrator;

class MigrationTest extends PHPUnit_Framework_TestCase
{
    public function testMigrationClasses()
    {
        $migrator = new Migrator();

        $this->setExpectedException(Exception::class);
        $migrator->registerMigration(self::class);
    }

    public function testSecondMigration()
    {
        $manager = Helper::createManager();

        $migrator = new Migrator();
        $migrator->migrate($manager);
        $migrator->migrate($manager);
    }
    public function testUsage()
    {
        $manager = Helper::createManager();

        $migrator = new Migrator();
        $migrator->registerMigration(CreatePosts::class);
        $migrator->migrate($manager);

        $post = $manager->get('posts')->create([
            'title' => 'hello world',
            'slug' => 'hello-world',
            'body' => 'This is hello world post',
            'author' => 'Dmitry Krokhin',
            'month' => 'March',
        ]);

        $manager->save($post);

        $this->assertNotNull($post->id);

        $migration = $manager->get('migrations')->find(['name' => CreatePosts::class]);
        $this->assertNotNull($migration);
    }
}
