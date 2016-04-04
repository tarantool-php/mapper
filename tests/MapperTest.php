<?php

class MapperTest extends PHPUnit_Framework_TestCase
{
    public function testArrayStorage()
    {
        $manager = Helper::createManager();
        $manager->getMeta()->create('rules', ['nick', 'list']);
        $data = [
            'nick' => 'test',
            'list' => ['first', 'second', 'q' => 'third'],
        ];
        $manager->create('rules', $data);

        $manager = Helper::createManager(false);

        $rules = $manager->get('rules', '1');
        $this->assertSame($rules->list, $data['list']);
    }
    public function testUsage()
    {
        $manager = Helper::createManager();
        $meta = $manager->getMeta()->create('post', ['title', 'slug', 'author', 'created_at']);
        $meta->addIndex('slug');
        $this->assertSame($meta->getPropertyType('id'), 'integer');
        $this->assertSame($meta->getPropertyType('title'), 'string');
        $this->assertSame($meta->getPropertyType('created_at'), 'integer');

        $postSpaceId = $manager->getSchema()->getSpaceId('post');
        $this->assertSame('post', $manager->getSchema()->getSpaceName($postSpaceId));

        $this->assertSame($meta->getProperties(), ['id', 'title', 'slug', 'author', 'created_at']);

        $post = $manager->get('post')->create([
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

        $rows = $manager->get('property')->bySpace($spaceId);
        $map = [];
        foreach ($rows as $property) {
            $map[$property->index] = $property->name;
        }
        ksort($map);

        $this->assertCount(3, $map);
        $this->assertSame($map, ['id', 'space', 'value']);

        $property = $manager->getMeta()->get('sequence')->getProperties();
        $this->assertSame($map, $property);
    }

    public function testEasycreate()
    {
        $manager = Helper::createManager();
        $manager->getMeta()->create('company', ['name']);

        $company = $manager->create('company', 'basis.company');
        $this->assertSame($company->name, 'basis.company');

        $manager->remove($company);

        $this->assertNull($manager->get('company', $company->id));

        $newManager = Helper::createManager(false);
        $this->assertNull($newManager->get('company', $company->id));
    }
}
