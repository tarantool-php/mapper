<?php

use Tarantool\Client\Middleware\LoggingMiddleware;
use Tarantool\Mapper\Mapper;
use Tarantool\Mapper\Plugin\Sequence;
use Tarantool\Mapper\Space;

class SqlTest extends TestCase
{
    public function testAutoincrement()
    {
        if (getenv('TARANTOOL_VERSION') == '1.10') {
            return $this->markTestSkipped('No SQL yet');
        }
        $mapper = $this->createMapper();
        $mapper->getPlugin(Sequence::class);
        $this->clean($mapper);

        $mapper->getClient()->executeUpdate('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, some_id INTEGER, test_id INTEGER)');
        $mapper->getClient()->executeUpdate('INSERT INTO users (some_id, test_id) VALUES (78, 1)');
        $mapper->getClient()->executeUpdate('INSERT INTO users (some_id, test_id) VALUES (43, 2)');
        $mapper->getClient()->executeUpdate('INSERT INTO users (some_id, test_id) VALUES (12, 1)');
        $mapper->getSchema()->reset();

        $this->assertCount(3, $mapper->find('USERS'));
        $this->assertCount(1, $mapper->find('USERS', ['id' => 2]));
        $this->assertSame(3, $mapper->findOne('USERS', ['id' => 3])->ID);
        
        $values = $mapper->getSchema()->getSpace('USERS')->getIndexValues(0, ['ID' => 5]);
        $this->assertSame($values, [5]);
    }
}
