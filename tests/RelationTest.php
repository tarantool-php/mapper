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

        $person = $manager->make('person', ['firstName' => 'Dmitry', 'lastName' => 'Krokhin']);
        $user = $manager->make('user', ['login' => 'nekufa', 'password' => 'password', 'info' => $person]);
        $recovery = $manager->make('recovery', ['token' => md5(time()), 'user' => $user]);

        $manager = Helper::createManager(false);
        $recovery = $manager->get('recovery')->find($recovery->getId());
        $this->assertSame($person->getId(), $recovery->user->info->getId());
    }
}
