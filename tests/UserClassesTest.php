<?php

use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Repository;
use Tarantool\Mapper\Space;
use Tarantool\Mapper\Plugin\UserClasses;

class UserClassesTest extends TestCase
{
    public function testInvalidEntity()
    {
        $mapper = $this->createMapper();
        $plugin = $mapper->getPlugin(UserClasses::class);
        $this->clean($mapper);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No space test');

        $plugin->mapEntity('test', TestEntity::class);
    }

    public function testUnknownEntityClass()
    {
        $mapper = $this->createMapper();
        $plugin = $mapper->getPlugin(UserClasses::class);
        $this->clean($mapper);

        $test = $mapper->getSchema()->createSpace('test');
        $test->addProperty('id', 'unsigned');
        $test->addProperty('name', 'string');
        $test->createIndex('id');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No class UnknownEntityClass');
        $plugin->mapEntity('test', UnknownEntityClass::class);
    }

    public function testInvalidEntityClass()
    {
        $mapper = $this->createMapper();
        $plugin = $mapper->getPlugin(UserClasses::class);
        $this->clean($mapper);

        $test = $mapper->getSchema()->createSpace('test');
        $test->addProperty('id', 'unsigned');
        $test->addProperty('name', 'string');
        $test->createIndex('id');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Entity should extend Tarantool\\Mapper\\Entity class');
        $plugin->mapEntity('test', InvalidTestEntity::class);
    }

    public function testEntityClass()
    {
        $mapper = $this->createMapper();
        $plugin = $mapper->getPlugin(UserClasses::class);
        $this->clean($mapper);

        $test = $mapper->getSchema()->createSpace('test');
        $test->addProperty('id', 'unsigned');
        $test->addProperty('name', 'string');
        $test->createIndex('id');

        $plugin->mapEntity('test', TestEntity::class);
        $this->assertSame($plugin->getEntityClass($test), TestEntity::class);

        $entity = $mapper->create('test', ['id' => 1, 'name' => 'hmm']);
        $this->assertInstanceOf(TestEntity::class, $entity);
    }

    public function testInvalidRepository()
    {
        $mapper = $this->createMapper();
        $plugin = $mapper->getPlugin(UserClasses::class);
        $this->clean($mapper);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No space test');

        $plugin->mapRepository('test', TestRepository::class);
    }

    public function testUnknownRepositoryClass()
    {
        $mapper = $this->createMapper();
        $plugin = $mapper->getPlugin(UserClasses::class);
        $this->clean($mapper);

        $test = $mapper->getSchema()->createSpace('test');
        $test->addProperty('id', 'unsigned');
        $test->addProperty('name', 'string');
        $test->createIndex('id');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No class UnknownRepositoryClass');
        $plugin->mapRepository('test', UnknownRepositoryClass::class);
    }

    public function testInvalidRepositoryClass()
    {
        $mapper = $this->createMapper();
        $plugin = $mapper->getPlugin(UserClasses::class);
        $this->clean($mapper);

        $test = $mapper->getSchema()->createSpace('test');
        $test->addProperty('id', 'unsigned');
        $test->addProperty('name', 'string');
        $test->createIndex('id');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Repository should extend Tarantool\\Mapper\\Repository class');
        $plugin->mapRepository('test', InvalidTestRepository::class);
    }

    public function testRepositoryClass()
    {
        $mapper = $this->createMapper();
        $plugin = $mapper->getPlugin(UserClasses::class);
        $this->clean($mapper);

        $test = $mapper->getSchema()->createSpace('test');
        $test->addProperty('id', 'unsigned');
        $test->addProperty('name', 'string');
        $test->createIndex('id');

        $plugin->mapRepository('test', TestRepository::class);
        $this->assertSame($plugin->getRepositoryClass($test), TestRepository::class);

        $this->assertInstanceOf(TestRepository::class, $test->getRepository());
    }
}

class InvalidTestEntity
{
}
class TestEntity extends Entity
{
}

class InvalidTestRepository
{
}
class TestRepository extends Repository
{
}
