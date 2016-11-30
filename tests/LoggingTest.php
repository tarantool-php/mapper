<?php

use Tarantool\Client\Client as TarantoolClient;
use Tarantool\Client\Connection\StreamConnection;
use Tarantool\Client\Packer\PurePacker;
use Tarantool\Mapper\Client;
use Tarantool\Mapper\Manager;

class LoggingTest extends PHPUnit_Framework_TestCase
{
    public function testBasicClientCanBeUsed()
    {
        // create client
        $port = getenv('TNT_CONN_PORT') ?: 3301;
        $connection = new StreamConnection('tcp://'.getenv('TNT_CONN_HOST').':'.$port);
        $client = new TarantoolClient($connection, new PurePacker());
        $manager = new Manager($client);
        $this->assertSame($manager->getClient(), $client);
    }

    public function testLoggingCanBeDisabled()
    {
        $manager = Helper::createManager();
        $client = $manager->getClient();
        $currentLog = $client->getLog();
        $client->setLogging(false);
        $client->ping();

        $this->assertSame($client->getLog(), $currentLog);
    }

    public function testClientLogsRequests()
    {
        $manager = Helper::createManager();
        $client = $manager->getClient();
        $this->assertInstanceOf(Client::class, $client);

        $person = $manager->getMeta()->create('person', ['name']);
        $person->addProperty('status');
        $person->addIndex(['name', 'status']);

        $manager->get('person', 1);
        $log = $client->getLog();

        $this->assertInternalType('array', $log);

        $firstEvent = $log[0];
        $lastEvent = $this->lastLogEvent($client);

        $this->assertSame('Make connection', $firstEvent->render($manager));
        $this->assertNotNull($firstEvent->getTime());

        $this->assertSame(
            'select person where id = 1',
            $lastEvent->render($manager)
        );

        $this->assertSame($lastEvent->getResponse(), []);

        // find in system space by primary with zero index
        $manager->getClient()->getSpace('_space')->select([]);
        $lastEvent = $this->lastLogEvent($client);

        // validate rendering
        $this->assertSame(
            'select _space where [0] = []',
            $lastEvent->render($manager)
        );

        // evaluate rendering test
        $manager->get('person')->evaluate('
            return box.space.person.index.id:select{1}
        ');
        $lastEvent = $this->lastLogEvent($client);
        $this->assertSame(
            'return box.space.person.index.id:select{1}',
            $lastEvent->render($manager)
        );
    }

    private function lastLogEvent($client)
    {
        $log = $client->getLog();

        return $log[count($log) - 1];
    }
}
