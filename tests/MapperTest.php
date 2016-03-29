<?php

class MapperTest extends PHPUnit_Framework_TestCase
{
    public function testUsage()
    {
        $manager = Helper::createManager();
        $meta = $manager->getMeta()->make('post', ['title', 'slug', 'author']);
        $meta->addIndex('slug');

        $this->assertSame($meta->getProperties(), ['id', 'title', 'slug', 'author']);

        $post = $manager->get('post')->make([
            'title' => 'Hello world',
            'slug' => 'hello-world',
            'author' => 'Dmitry Krokhin',
        ]);

        $manager->save($post);

        $this->assertNotNull($post->getId());
        $this->assertSame($post, $manager->get('post')->findOne(['slug' => 'hello-world']));
        $this->assertSame($post, $manager->get('post')->oneBySlug('hello-world'));

        $post->title .= '!';
        $manager->save($post);

        $this->assertSame($post->title, 'Hello world!');

        $manager = Helper::createManager(false);
        $anotherInstance = $manager->get('post')->find(['id' => $post->id], true);
        $this->assertNotNull($anotherInstance);

        $this->assertSame($anotherInstance->title, $post->title);
    }

    public function testBasicMapping()
    {
        $manager = Helper::createManager();

        $rows = $manager->get('mapping')->bySpace('sequences');
        $map = [];
        foreach ($rows as $row) {
            $map[$row->line] = $row->property;
        }
        ksort($map);

        $this->assertCount(3, $map);
        $this->assertSame($map, ['id', 'name', 'value']);

        $mapping = $manager->getMeta()->get('sequences')->getMapping();
        $this->assertSame($map, $mapping);
    }
}
