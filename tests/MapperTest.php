<?php

class MapperTest extends PHPUnit_Framework_TestCase
{
    public function testPropertyRemoveRemovesIndex()
    {
        $manager = Helper::createManager();
        $person = $manager->getMeta()->create('person', ['name', 'status']);
        $person->addIndex('status');
        $person->removeProperty('status');
        $this->assertSame($person->getIndexes(), [['id']]);
    }

    public function testPropertyAddRemove()
    {
        $manager = Helper::createManager();
        $meta = $manager->getMeta();
        $person = $meta->create('person', ['firstName', 'lastName', 'status']);
        $person->removeProperty('status');

        $this->assertSame($person->getProperties(), ['id', 'firstName', 'lastName']);

        $person->addProperty('status');
        $this->assertSame($person->getProperties(), ['id', 'firstName', 'lastName', 'status']);
    }

    public function testRemoveValidation()
    {
        $manager = Helper::createManager();
        $manager->getMeta()->create('person', ['name']);
        $manager->create('person', 'Dmitry');
        $this->setExpectedException(Exception::class);
        $manager->getMeta()->remove('person');
    }

    public function testClearSpace()
    {
        $manager = Helper::createManager();
        $manager->getMeta()->create('item', ['name']);
        $manager->create('item', 'ferrari');
        $manager->create('item', 'bugatti');
        $manager->create('item', 'lada');

        $this->assertCount(3, $manager->get('item')->find([]));
        $manager->get('item')->removeAll();
        $this->assertCount(0, $manager->get('item')->find([]));
    }

    public function testRemove()
    {
        $manager = Helper::createManager();
        $person = $manager->getMeta()->create('person', ['name']);
        $spaceId = $person->getSpaceId();
        $dmitry = $manager->create('person', 'Dmitry');
        $manager->remove($dmitry);
        $manager->getMeta()->remove('person');

        $this->assertCount(0, $manager->get('property')->bySpace($spaceId));
        $this->assertCount(0, $manager->getClient()->getSpace('_vindex')->select([$spaceId], 'primary')->getData());

        $this->assertFalse($manager->getMeta()->has('person'));

        $newPerson = $manager->getMeta()->create('person', ['login']);
        $this->assertNotSame($person, $newPerson);
        $nekufa = $manager->create('person', ['login' => 'nekufa']);
        $this->assertSame($nekufa->id, 1);
    }
    public function testNoIndex()
    {
        $manager = Helper::createManager();
        $manager->getMeta()->create('portal', ['name', 'routing']);
        $manager->create('portal', ['name' => 'developer', 'routing' => ['/' => 'web@index']]);
        $this->setExpectedException(Exception::class);
        $manager->get('portal')->find(['name' => 'developer']);
    }

    public function testAddProperty()
    {
        $manager = Helper::createManager();
        $manager->getMeta()->create('unit', ['name']);
        $manager->create('unit', 'tester');

        $manager->getMeta()->get('unit')->addProperty('rating', 'integer');
        $unit = $manager->get('unit', 1);
        $unit->rating = 5;
        $manager->save($unit);

        $this->assertSame(5, $unit->rating);
    }

    public function testAddPropertyLater()
    {
        $manager = Helper::createManager();
        $manager->getMeta()->create('unit', ['name']);
        $manager->create('unit', 'tester');

        $anotherManager = Helper::createManager(false);
        $anotherManager->getMeta()->get('unit')->addProperty('note');
        $anotherManager->getMeta()->get('unit')->addProperty('rating', 'integer');
        $unit = $anotherManager->get('unit', 1);
        $unit->rating = 15;
        $anotherManager->save($unit);
        $this->assertSame($unit->rating, 15);
    }

    public function testMultiplePropertyType()
    {
        $manager = Helper::createManager();
        $task = $manager->getMeta()->create('task', ['year', 'month', 'day']);
        $task->setPropertyType(['year', 'month', 'day'], 'integer');
        $this->assertSame('integer', $task->getPropertyType('year'));
        $this->assertSame('integer', $task->getPropertyType('month'));
        $this->assertSame('integer', $task->getPropertyType('day'));
    }
    public function testEntityPropertiesCheck()
    {
        $manager = Helper::createManager();
        $manager->getMeta()->create('person', ['firstName', 'lastName']);
        $this->setExpectedException(Exception::class);
        $manager->create('person', ['name' => 'Dmitry Krokhin']);
    }
    public function testEntityConstructorCheck()
    {
        $manager = Helper::createManager();
        $manager->getMeta()->create('rules', ['nick', 'list']);

        $this->setExpectedException(Exception::class);
        $manager->get('rules')->create('test');
    }
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
        $this->assertNotNull($rules);
        $this->assertSame($rules->list, $data['list']);
    }

    public function testNoType()
    {
        $this->setExpectedException(Exception::class);
        $meta = Helper::createManager()->getMeta();
        $comments = $meta->get('comments');
    }
    public function testDuplicateType()
    {
        $meta = Helper::createManager()->getMeta();
        $comments = $meta->create('comments', ['author', 'name']);
        $this->setExpectedException(Exception::class);
        $comments = $meta->create('comments', ['author', 'name']);
    }
    public function testDuplicateProperty()
    {
        $meta = Helper::createManager()->getMeta();
        $comments = $meta->create('comments');
        $comments->addProperty('author');
        $this->setExpectedException(Exception::class);
        $comments->addProperty('author');
    }
    public function testNoIndexProperty()
    {
        $meta = Helper::createManager()->getMeta();
        $comments = $meta->create('comments', ['author', 'name']);
        $this->setExpectedException(Exception::class);
        $comments->addIndex('document_id');
    }
    public function testDuplicateIndex()
    {
        $meta = Helper::createManager()->getMeta();
        $comments = $meta->create('comments', ['author', 'name']);
        $comments->addIndex('author');
        $this->setExpectedException(Exception::class);
        $comments->addIndex('author');
    }

    public function testIdUpdate()
    {
        $post = new Tarantool\Mapper\Entity([
            'title' => 'testing',
        ]);
        $this->assertNull($post->getId());
        $post->setId(1);
        $this->setExpectedException(Exception::class);
        $post->setId(2);
    }

    public function testNoMethod()
    {
        $manager = Helper::createManager();
        $comments = $manager->getMeta()->create('comments', ['author', 'name']);
        $this->setExpectedException(Exception::class);
        $manager->get('comments')->flyToTheMoon();
    }

    public function testRepositoryCreationOnly()
    {
        $manager = Helper::createManager();
        $comments = $manager->getMeta()->create('comments', ['author', 'name']);
        $this->setExpectedException(Exception::class);
        $manager->get('comments')->save(new Tarantool\Mapper\Entity());
    }

    public function testNoEntityRelation()
    {
        $manager = Helper::createManager();
        $post = new Tarantool\Mapper\Entity([
            'title' => 'testing',
        ]);
        $this->setExpectedException(Exception::class);
        $manager->save($post);
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

    public function testById()
    {
        $manager = Helper::createManager();
        $manager->getMeta()->create('company', ['name']);

        $company = $manager->create('company', 'basis.company');
        $manager->get('company')->find(1);

        $this->assertSame($company->getData(), ['name' => 'basis.company', 'id' => 1]);
    }
}
