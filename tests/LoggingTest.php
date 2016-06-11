<?php

use Tarantool\Mapper\Client;

class LoggingTest extends PHPUnit_Framework_TestCase
{
    public function testClientLogsRequests()
    {
        $manager = Helper::createManager();
        $client = $manager->getClient();
        $this->assertInstanceOf(Client::class, $client);

        $manager->getMeta()->create('person', ['name']);
        $manager->get('person', 1);
        $log = $client->getLog();

        $this->assertInternalType('array', $log);

        $firstEvent = $log[0];
        $lastEvent = $log[count($log) - 1];

        $this->assertSame('Make connection', $firstEvent->render($manager));

        $this->assertSame(
            'select person where id = 1',
            $lastEvent->render($manager)
        );

        $this->assertSame($lastEvent->getResponse(), []);
    }
}
