<?php

use Tarantool\Mapper\Contracts\Entity;

class RelationTest extends PHPUnit_Framework_TestCase
{
    public function testRemove()
    {
        $manager = Helper::createManager();
        $meta = $manager->getMeta();
        $sector_type = $meta->create('sector_type', ['name']);
        $meta->create('sector', [$sector_type]);

        $this->setExpectedException(Exception::class);
        $meta->remove('sector_type');
    }

    public function testUsage()
    {
        $manager = Helper::createManager();
        $meta = $manager->getMeta();

        $person = $meta->create('person', ['firstName', 'lastName']);
        $user = $meta->create('user', ['login', 'password'])->reference($person, 'info');
        $recovery = $meta->create('recovery', ['token'])->reference($user)->addIndex('token');

        $this->assertSame($user->getReferences(), ['info' => 'person']);
        $this->assertSame($recovery->getReferences(), ['user' => 'user']);

        $person = $manager->create('person', ['firstName' => 'Dmitry', 'lastName' => 'Krokhin']);
        $user = $manager->create('user', ['login' => 'nekufa', 'password' => 'password', 'info' => $person]);
        $recovery = $manager->create('recovery', ['token' => md5(time()), 'user' => $user]);

        $manager = Helper::createManager(false);
        $recovery = $manager->get('recovery')->find($recovery->getId());
        $this->assertSame($person->getId(), $recovery->user);

        $required = ['id', 'user', 'token'];
        sort($required);
        $calcRequired = $manager->getMeta()->get('recovery')->getRequiredProperties();
        sort($calcRequired);
        $this->assertSame($required, $calcRequired);

        $recovery = $manager->create('recovery', ['token' => md5('test')]);
        $this->assertNull($recovery->user);

        $recovery = $manager->create('recovery', ['user' => 1]);
        $this->assertNull($recovery->token);
    }

    public function testTwoRelation()
    {
        $manager = Helper::createManager();
        $meta = $manager->getMeta();

        $doc = $meta->create('document', ['type']);
        $item = $meta->create('item', ['name']);
        $meta->create('document_details', [$doc, $item, 'qty']);

        $itemMapping = $manager->get('property')->byType('item');
        $this->assertCount(1, $itemMapping);
        $this->assertSame($itemMapping[0]->space, $manager->getSchema()->getSpaceId('document_details'));

        $items = [
            $manager->create('item', 'Jack Daniels\' No.7'),
            $manager->create('item', 'Chivas Regal 18'),
        ];

        $gift = $manager->create('document', 'gift');

        $manager->create('document_details', [$gift, 'item' => $items[0]]);
        $detail = $manager->create('document_details', [$items[1], 'document' => $gift]);

        $manager->create('document_details', ['item' => 100500, 'document' => 100500]);
        $manager->create('document_details', ['item' => '100500', 'document' => '100500']);

        $array = $detail->toArray(true);
        $this->assertSame($array['item'], $items[1]->id);

        $newManager = Helper::createManager(false);
        $gift = $newManager->get('document', $gift->id);
        $details = $newManager->get('document_details')->byDocument($gift);
        $this->assertSame($details[0]->document, $gift->id);

        $detailsById = $newManager->get('document_details')->byDocument(''.$gift->id);
        $this->assertSame($detailsById, $details);

        $newIds = [];
        foreach ($details as $row) {
            $newIds[] = $row->id;
        }

        $this->assertCount(2, $newIds);

        $originalIds = [];
        foreach ($items as $item) {
            $originalIds[] = $item->id;
        }

        sort($newIds);
        sort($originalIds);
        $this->assertSame($newIds, $originalIds);

        $detailsByEntity = $newManager->get('document_details', $gift);
        $this->assertCount(2, $detailsByEntity);

        $detailsByArrayWithEntity = $newManager->get('document_details', [$gift]);
        $this->assertCount(2, $detailsByArrayWithEntity);
    }

    public function testNoReference()
    {
        $manager = Helper::createManager();
        $person = $manager->getMeta()->create('person', ['name']);
        $manager->getMeta()->create('post', ['title']);

        $this->setExpectedException(LogicException::class);
        $manager->create('post', [
            'title' => 'Hello world!',
            $manager->create('person', 'Dmitry'),
        ]);
    }

    public function testMultipleReference()
    {
        $manager = Helper::createManager();
        $person = $manager->getMeta()->create('person', ['name']);
        $manager->getMeta()->create('post', ['reviewer' => $person, 'author' => $person]);

        $manager->create('post', [
            'reviewer' => $manager->create('person', 'Alexander'),
            'author' => $manager->create('person', 'Dmitry'),
        ]);

        $this->setExpectedException(LogicException::class);
        $manager->create('post', [$manager->create('person', 'superman')]);
    }

    public function testRelationQuery()
    {
        $manager = Helper::createManager();
        $meta = $manager->getMeta();
        $meta->create('unit_param', [
            $meta->create('unit', ['name']),
            $meta->create('param', ['name']),
            'value',
        ])->setPropertyType('value', 'integer');

        $meta->get('unit_param')->addIndex(['unit', 'value']);
        $meta->get('unit')->addIndex('name');

        $unit = $manager->create('unit', ['vm310']);

        $manager->create('unit_param', [
            $unit,
            $manager->create('param', 'height'),
            310,
        ]);

        $manager->create('unit_param', [
            $unit,
            $manager->create('param', 'levels'),
            13,
        ]);

        $manager->create('unit_param', [
            $manager->create('unit', 'road to nowhere'),
            $manager->create('param', 'length'),
            65,
        ]);

        $manager->create('unit_param', [
            $manager->create('unit', 'Baba Valya'),
            $manager->create('param', 'age'),
            65,
        ]);

        $this->assertCount(4, $manager->get('unit_param')->find());
        $this->assertCount(2, $manager->get('unit_param', $unit));
        $this->assertCount(1, $manager->get('unit_param', [$unit, 'value' => 310]));

        $valya = $manager->get('unit')->find(['name' => 'Baba Valya'])[0];
        $this->assertInstanceOf(Entity::class, $valya);
        $this->assertCount(1, $manager->get('unit_param', $valya));
        $this->assertCount(1, $manager->get('unit_param')->find([
            $valya,
            'value' => 65,
        ]));
    }
}
