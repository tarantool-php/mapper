<?php

class MapperTest extends PHPUnit_Framework_TestCase
{
    public function testUsage()
    {
        $manager = Helper::createManager();
        $meta = $manager->getMeta()->make('post', ['title', 'slug', 'author', 'created_at']);
        $meta->addIndex('slug');
        $this->assertSame($meta->getPropertyType('id'), 'integer');
        $this->assertSame($meta->getPropertyType('title'), 'string');
        $this->assertSame($meta->getPropertyType('created_at'), 'integer');

        $postSpaceId = $manager->getSchema()->getSpaceId('post');
        $this->assertSame('post', $manager->getSchema()->getSpaceName($postSpaceId));

        $this->assertSame($meta->getProperties(), ['id', 'title', 'slug', 'author', 'created_at']);

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

        $spaceId = $manager->getSchema()->getSpaceId('sequence');

        $rows = $manager->get('mapping')->bySpace($spaceId);
        $map = [];
        foreach ($rows as $row) {
            $map[$row->line] = $row->property;
        }
        ksort($map);

        $this->assertCount(3, $map);
        $this->assertSame($map, ['id', 'space', 'value']);

        $mapping = $manager->getMeta()->get('sequence')->getMapping();
        $this->assertSame($map, $mapping);
    }

    public function testEasyMake()
    {
        $manager = Helper::createManager();
        $manager->getMeta()->make('company', ['name']);

        $company = $manager->make('company', 'basis.company');
        $this->assertSame($company->name, 'basis.company');
    }
}
