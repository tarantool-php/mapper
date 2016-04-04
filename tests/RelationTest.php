<?php

class RelationTest extends PHPUnit_Framework_TestCase
{
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

        $recovery = $manager->create('recovery', [$recovery->user]);
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

        $manager->create('document_details', [$gift, $items[0]]);
        $detail = $manager->create('document_details', [$items[1], $gift]);

        $array = $detail->toArray(true);
        $this->assertSame($array['item']['name'], $items[1]->name);

        $newManager = Helper::createManager(false);
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
    }
}
