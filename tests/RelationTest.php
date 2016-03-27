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

        $person = $manager->make('person', ['firstName' => 'Dmitry', 'lastName' => 'Krokhin']);
        $user = $manager->make('user', ['login' => 'nekufa', 'password' => 'password', 'info' => $person]);
        $recovery = $manager->make('recovery', ['token' => md5(time()), 'user' => $user]);

        $manager = Helper::createManager(false);
        $recovery = $manager->get('recovery')->find($recovery->getId());
        $this->assertSame($person->getId(), $recovery->user->info->getId());
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

        $manager->make('document_details', ['document' => $gift, 'item' => $items[0]]);
        $manager->make('document_details', ['document' => $gift, 'item' => $items[1]]);

        $newManager = Helper::createManager(false);
        $details = $newManager->get('document_details')->byDocument($gift);
        $this->assertSame($details[0]->document->id, $gift->id);

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
