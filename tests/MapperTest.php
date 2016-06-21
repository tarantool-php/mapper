<?php

use Tarantool\Mapper\Contracts\Entity;

class MapperTest extends PHPUnit_Framework_TestCase
{
    public function testNotEquals()
    {
        $manager = Helper::createManager();
        $person = $manager->getMeta()->create('person', ['name']);
        $manager->create('person', 'dmitry');
        $manager->create('person', 'vasiliy');
        $manager->create('person', 'vladimir');

        $this->assertCount(2, $manager->get('person')->find(['id' => '!2']));

        $task = $manager->getMeta()->create('task', ['unit', 'sector', 'title']);
        $task->setPropertyType(['unit', 'sector'], 'integer');
        $task->addIndex(['unit'], ['unique' => false]);

        $manager->create('task', ['unit' => 1, 'sector' => 1]);
        $manager->create('task', ['unit' => 2, 'sector' => 2]);
        $manager->create('task', ['unit' => 1, 'sector' => 2]);

        $this->assertCount(2, $manager->get('task')->find([
            'unit' => 1
        ]));

        $this->assertCount(1, $manager->get('task')->find([
            'unit' => 1, 'sector' => '!2'
        ]));

        $this->assertCount(1, $manager->get('task')->find([
            'unit' => "!1"
        ]));
    }

    public function testEvalution()
    {
        $manager = Helper::createManager();
        $person = $manager->getMeta()->create('person', ['name']);
        $relation = $manager->getMeta()->create('person_sector', ['sector', $person]);
        $relation->setPropertyType('sector', 'integer');
        $relation->addIndex('sector', ['unique' => false]);
        foreach (range(1, 10) as $number) {
            $manager->create('person_sector', [
                'sector' => $number % 2,
                'person' => $manager->create('person', "Person $number"),
            ]);
        }

        $persons = $manager->get('person')->evaluate('
            local result = {}
            for n, link in box.space.person_sector.index.sector:pairs(1) do
                table.insert(result, box.space.person:get(link[3]))
            end
            return result
        ');

        $this->assertCount(5, $persons);
        foreach ($persons as $person) {
            $this->assertSame(1, substr($person->name, 7) % 2);
        }
    }

    public function testIncorrectQueryParamsShouldProvideAnException()
    {
        $manager = Helper::createManager();
        $manager->getMeta()
            ->create('person', ['firstName', 'lastName'])
            ->addIndex('firstName', ['unique' => false]);

        $this->setExpectedException(Exception::class);
        $manager->get('person')->find(['reach' => true]);
    }

    public function testStringCasting()
    {
        $manager = Helper::createManager();
        $manager->getMeta()->create('person', ['firstName', 'lastName'])->addIndex('firstName', ['unique' => false]);

        $person = $manager->create('person', ['firstName' => 123, 'lastName' => 456]);
        $this->assertSame($person->firstName, '123');
        $this->assertSame($person->lastName, '456');
    }
    public function testInstanceRemoveFlushCache()
    {
        $manager = Helper::createManager();
        $manager->getMeta()->create('person', ['firstName', 'lastName'])->addIndex('firstName', ['unique' => false]);

        $person = $manager->create('person', ['firstName' => 'Dmitry', 'lastName' => 'Krokhin']);
        $this->assertCount(1, $manager->get('person', ['firstName' => 'Dmitry']));

        $manager->remove($person);
        $this->assertCount(0, $manager->get('person', ['firstName' => 'Dmitry']));
    }

    public function testInstanceCreationFlushCache()
    {
        $manager = Helper::createManager();
        $manager->getMeta()->create('person', ['firstName', 'lastName'])->addIndex('firstName', ['unique' => false]);

        $manager->create('person', ['firstName' => 'Dmitry', 'lastName' => 'Krokhin']);
        $this->assertCount(1, $manager->get('person', ['firstName' => 'Dmitry']));

        $manager->create('person', ['firstName' => 'Dmitry', 'lastName' => 'Fedishin']);
        $this->assertCount(2, $manager->get('person', ['firstName' => 'Dmitry']));
    }

    public function testFindOneCache()
    {
        $manager = Helper::createManager();
        $manager->getMeta()->create('document_type', ['nick'])->addIndex('nick');

        $manager->create('document_type', 'lost');
        $manager->create('document_type', 'received');

        $this->assertCount(1, $manager->get('document_type')->find(['nick' => 'lost']));

        $this->assertInstanceOf(Entity::class, $manager->get('document_type')->findOne(['nick' => 'lost']));
    }

    public function testLaterReference()
    {
        $manager = Helper::createManager();
        $manager->getMeta()->create('sector', ['a', 'v', 'qqqq'])->addIndex(['v', 'qqqq']);
        $t = $manager->getMeta()->create('task', ['q', 'w', 'e', 'r', 't'])->addIndex(['q', 'w']);
        $t->addProperty('test');
        $t->setPropertyType('test', 'integer');

        $laterManager = Helper::createManager(false);
        $meta = $laterManager->getMeta();
        $sector = $meta->get('sector');
        $meta->get('task')->reference($sector);
    }

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
        $manager->get('comments')->save(new Tarantool\Mapper\Entities\Entity());
    }

    public function testNoEntityRelation()
    {
        $manager = Helper::createManager();
        $post = new Tarantool\Mapper\Entities\Entity([
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

        $this->assertSame($company->toArray(), ['id' => 1, 'name' => 'basis.company']);
    }

    public function testCreateEmpty()
    {
        $manager = Helper::createManager();
        $manager->getMeta()->create('company', ['name']);

        $company = $manager->create('company');
        $this->assertNotNull($company->id);
        $this->assertNull($company->name);

        $newManager = Helper::createManager(false);
        $newCompany = $manager->get('company', $company->id);
        $this->assertNotNull($newCompany->id);
        $this->assertNull($newCompany->name);
    }
}
