<?php

class RelationTest extends PHPUnit_Framework_TestCase
{
    public function testUsage()
    {
        $manager = Helper::createManager();
        $meta = $manager->getMeta();

        $person = $meta->make('person', ['firstName', 'lastName']);
        $user = $meta->make('user', ['login', 'password'])->reference($person, 'info');
        $recovery = $meta->make('recovery', ['token'])->reference($user)->addIndex('token');

        $this->assertSame($user->getReferences(), ['info' => 'person']);
        $this->assertSame($recovery->getReferences(), ['user' => 'user']);

        $person = $manager->make('person', ['firstName' => 'Dmitry', 'lastName' => 'Krokhin']);
        $user = $manager->make('user', ['login' => 'nekufa', 'password' => 'password', 'info' => $person]);
        $recovery = $manager->make('recovery', ['token' => md5(time()), 'user' => $user]);

        $manager = Helper::createManager(false);
        $recovery = $manager->get('recovery')->find($recovery->getId());
        $this->assertSame($person->getId(), $recovery->user);

        $this->assertSame(['id', 'user', 'token'], $manager->getMeta()->get('recovery')->getRequiredProperties());

        $recovery = $manager->make('recovery', ['token' => md5('test')]);
        $this->assertNull($recovery->user);

        $recovery = $manager->make('recovery', [$recovery->user]);
        $this->assertNull($recovery->token);
    }
    public function testTwoRelation()
    {
        $manager = Helper::createManager();
        $meta = $manager->getMeta();

        $doc = $meta->make('document', ['type']);
        $item = $meta->make('item', ['name']);
        $meta->make('document_details', [$doc, $item, 'qty']);

        $items = [
            $manager->make('item', ['name' => 'Jack Daniels\' No.7']),
            $manager->make('item', ['name' => 'Chivas Regal 18']),
        ];

        $gift = $manager->make('document', ['type' => 'gift']);

        $manager->make('document_details', [$gift, $items[0]]);
        $detail = $manager->make('document_details', [$items[1], $gift]);

        $array = $detail->toArray(true);
        $this->assertSame($array['item']['name'], $items[1]->name);

        $newManager = Helper::createManager(false);
        $details = $newManager->get('document_details')->byDocument($gift);
        $this->assertSame($details[0]->document, $gift->id);

        $detailsById = $newManager->get('document_details')->byDocument($gift->id);
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
