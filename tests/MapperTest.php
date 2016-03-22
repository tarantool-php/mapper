<?php

use Tarantool\Client;
use Tarantool\Mapper\Manager;

use Tarantool\Mapper\Migrations\Collection as MigrationCollection;
use Tarantool\Connection\SocketConnection;
use Tarantool\Packer\PurePacker;

use Tarantool\Schema\Space;
use Tarantool\Schema\Index;

class MapperTest extends PHPUnit_Framework_TestCase
{
    public function testUsage()
    {
        $manager = self::createManager();
        $meta = $manager->getMetadata()->create('post', ['title', 'slug', 'author']);
        $meta->addIndex('slug');

        $post = $manager->get('post')->make([
            'title' => 'Hello world',
            'slug' => 'hello-world',
            'author' => 'Dmitry Krokhin'
        ]);

        $manager->save($post);

        $this->assertNotNull($post->getId());
        $this->assertSame($post, $manager->get('post')->oneBySlug('hello-world'));

        $post->title .= '!';
        $manager->save($post);

        $this->assertSame($post->title, 'Hello world!');
    }

    public function testBasicMapping()
    {
        $manager = self::createManager();

        $rows = $manager->get('mapping')->bySpace('sequence');
        $map = [];
        foreach($rows as $row) {
            $map[$row->line] = $row->property;
        }
        ksort($map);

        $this->assertCount(3, $map);
        $this->assertSame($map, ['id', 'name', 'value']);

        $mapping = $manager->getMetadata()->get('sequence')->getMapping();
        $this->assertSame($map, $mapping);
    }

    public static function createManager()
    {
        // create client
        $connection = new SocketConnection(getenv('TNT_CONN_HOST'));
        $client = new Client($connection, new PurePacker());

        // flush everything
        $schema = new Space($client, Space::VSPACE);
        $response = $schema->select([], Index::SPACE_NAME);
        $data = $response->getData();
        foreach($data as $row) {
            if($row[1] == 0) {
                // user space
                $client->evaluate('box.schema.space.drop('.$row[0].')');
            }
        }

        // create fresh manager instance
        $manager = new Manager($client);

        // boostrap
        $migration = new MigrationCollection();
        $migration->migrate($manager);

        return $manager;
    }
}