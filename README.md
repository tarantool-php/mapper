# Tarantool Mapper
[![License](https://poser.pugx.org/tarantool/mapper/license.png)](https://packagist.org/packages/tarantool/mapper)
[![Testing](https://github.com/tarantool-php/mapper/actions/workflows/tests.yml/badge.svg)](https://github.com/tarantool-php/mapper/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/github/release/tarantool-php/mapper.svg?style=flat-square)](https://github.com/tarantool-php/mapper/releases)
[![Total Downloads](https://img.shields.io/packagist/dt/tarantool/mapper.svg?style=flat-square)](https://packagist.org/packages/tarantool/mapper)
[![Telegram](https://img.shields.io/badge/Telegram-join%20chat-blue.svg)](https://t.me/tarantool_php)

- [Getting Started](#getting-started)
- [Schema Management](#schema-management)
- [Working with the data](#working-with-the-data)
- [Schema Cache](#schema-cache)
- [Query Cache](#query-cache)
- [Changes registration](#changes-registration)
- [Multiple connections](#multiple-connections)
- [Lua code delivery](#lua-code-delivery)
- [Performance](#performance)

## Getting started
Supported versions are php 8+ and tarantool 2+.\
The recommended way to install the library is through [Composer](http://getcomposer.org):
```bash
composer require tarantool/mapper
```

Usually, you manage dependencies in your service provider.\
To get started you should create client instance and pass it to mapper constructor.\
In this example we use PurePacker and StreamConnection.\
To see other implementations please check [client documentation](https://github.com/tarantool-php/client#creating-a-client)

```php
use Tarantool\Client\Client;
use Tarantool\Mapper\Mapper;

$client = Client::fromDefaults();
$mapper = new Mapper($client);

// internaly mapper wraps client with special middleware
assert($mapper->client !== $client);
```

## Schema management
To get started you should describe your spaces, their format and indexes.
```php
$person = $mapper->createSpace('person', [
    'engine' => 'memtx',
    'if_not_exists' => true,
]);

// add properties - name, type and options
$person->addProperty('id', 'unsigned');
$person->addProperty('name', 'string');
$person->addProperty('birthday', 'unsigned');
$person->addProperty('gender', 'string', [
    'default' => 'male'
]);

// indexes are created using fields array and optional index configuration
$person->addIndex(['name']);
$person->addIndex(['birthday'], ['unique' => true]);

// index name is fields based, but you can specify any preffered one
$person->addIndex(['name', 'birthday'], [
    'type' => 'hash',
    'name' => 'name_with_birthday',
]);

/**
 * define format using properties
 */
class Tracker
{
    public int $id;
    public int $reference;
    public string $status;

    public static function initSchema(\Tarantool\Mapper\Space $space)
    {
        $space->addIndex(['reference']);
    }
}

$tracker = $mapper->createSpace('tracker');
$tracker->setClass(Tracker::class);
$tracker->migrate();

/**
 * define format using constructor promotion
 */
class Policy
{
    public function __construct(
        public int $id,
        public string $nick,
        public string $status,
    ) {
    }

    public static function initialize(\Tarantool\Mapper\Space $space)
    {
        $space->addIndex(['nick'], ['unique' => true]);
    }
}

$policy = $mapper->createSpace('policy');
$policy->setClass(Policy::class, 'initialize'); // use custom initialize method
$policy->migrate();
```

## Working with the data
Now you can store and retreive data from tarantool storage using mapper instance.
```php
// get space instance
$persons = $mapper->getSpace('person');

// create new entity
$dmitry = $persons->create([
    'id' => 1,
    'name' => 'Dmitry'
]);

// create entities using mapper wrapper.
// this way entity will be created and saved in the tarantool
$vasily = $mapper->create('person', [
    'id' => 2,
    'name' => 'Vasily'
]);

// retreive entites by id using space
$helloWorld = $mapper->getSpace('post')->findOne(['id' => 3]);

// or using mapper wrapper
$helloWorld = $mapper->findOne('post', ['id' => 3]);

// pass client criteria object as well
$criteria = Criteria::index('age')->andKey([18])->andGeIterator();
$adults = $mapper->find('user', $criteria);

// updates are easy
$posts = $mapper->getSpace('post');
$helloWorld = $posts->update($helloWorld, [
    'title' => 'Hello world'
]);

// if you use instance classes, instance would be updated
$policy = $mapper->findOrFail('policy', ['id' => 3]);
$policy = $mapper->get('policy', 3); // getter shortcut
$mapper->update('policy', $policy, [
    'title' => 'updated title',
]);
echo $policy->title; // updated title

// use client operations as well
use Tarantool\Client\Schema\Operations;
$mapper->getSpace('policy')->update($policy, Operations::add('counter', 1));
var_dump($policy->counter); // actual value
```

## Schema Cache
Any new mapper instance will fetch schema from the tarantool, this requests can takes a bit of database load.\
Use your favorite `psr/cache` implementation to persist schema on the application side.\
For example, we use apcu adapter from `symfony/cache` package.\
If new schema version is not persisted in cache, mapper will fetch it
```php
use Symfony\Component\Cache\Adapter\ApcuAdapter;
$cache = new ApcuAdapter();

$mapper = new Mapper(Client::fromDefaults());
$mapper->cache = $cache;
$mapper->getSpace('_vspace'); // schema is fetched now

$mapper = new Mapper(Client::fromDefaults());
$mapper->cache = $cache;
$mapper->getSpace('_vspace'); // no new requests are made
```

## Query Cache
If you don't want to perform select queries you can inject cache interface to space instance.\
Use your favorite psr/cache implementation to persist schema on the application side.\
For example, we use array adapter from `symfony/cache` package.
```php
use Symfony\Component\Cache\Adapter\ArrayAdapter;

$mapper = new Mapper(Client::fromDefaults());
$mapper->getSpace('_vspace')->cache = new ArrayAdapter(); // feel free to set default ttl

$mapper->find('_vspace'); // query is executed
$mapper->find('_vspace'); // results are fetched from cache
$mapper->find('_vspace'); // results are fetched from cache
```

## Changes registration
In some cases you want to get all changes that were made during current session.\
By default spy configuration is set to false, this improves performance a bit.
```php
$mapper->spy = true;

$nekufa = $mapper->create('user', ['login' => 'nekufa']);
$firstPost = $mapper->create('post', [
    'user_id' => $nekufa->id,
    'title' => 'hello world',
]);
$mapper->update('post', $firstPost, ['title' => 'Final title']);

// now there are two changes
[$first, $second] = $mapper->getChanges();
echo $first->type; // insert
echo $first->space; // user
echo $first->data; // ['login' => 'nekufa']

// all changes would be merged by space and key
// this reduces changes duplicates
echo $second->type; // insert
echo $second->space; // post
echo $second->data; // ['user_id' => 1, 'title' => 'Final title']

// of course you can flush all changes and start registration from scratch
$mapper->flushChanges();
```
## Multiple connections
If you split your data across multiple tarantool instances you can use prefix based data api.\
Api is the same but you prefix space name with a connection prefix.
```php
$pool = new Pool(function (string $prefix) {
    return new Mapper(Client::fromDsn('tcp://' . $prefix));
});

// connect to tarantool instance `volume` and find all timelines.
$trackers = $pool->findOne('volume.timeline');

$nekufa = $pool->findOrCreate('guard.login', ['username' => 'nekufa']);
$pool->update('guard.login', $nekufa, ['locked_at' => time()]);

// pool also wraps changes with the prefixes
echo $pool->getChanges()[0]->space; // guard.login

// all expressions do the same behind the scenes
$pool->find('flow.tracker', ['status' => 'active']);
$pool->getMapper('flow')->find('tracker', ['status' => 'active']);
$pool->getMapper('flow')->getSpace('tracker')->find(['status' => 'active']);
```

## Lua code delivery
Iproto usage is very powerful but sometimes is not enough.\
You can easily execute lua code and pass local variables using associative array.

In addition, if you don't want to deliver it every request, use magic `call` method.\
When you use call method, mapper generates unique function name and creates it if it's not exist.
```php
// this method will always deliver and parse lua code on the tarantool side
$mapper->evaluate('return a + b', ['a' => 2, 'b' => 7]); // 9

// first call a function would be created with name evaluate_{BODYHASH}
// there would be two requests - create function and call it
$mapper->call('return a + b', ['a' => 2, 'b' => 7]); // 9

// second call will produce single request with function name and arguments
$mapper->call('return a + b', ['a' => 2, 'b' => 7]); // 9
```

## Migrations
Use basic migration class to implement some logic before or after schema creation.\
Pass migrations to mapper migrate method and that's all.
```phpu

use Tarantool\Mapper\Migration;
use Tarantool\Mapper\Space;

class DropLegacySpaces extends Migration
{
    public function beforeSchema(Mapper $mapper)
    {
        $mapper->call(<<<LUA
            if box.space.legacy then
                box.space.legacy:drop()
                box.space.legacy_detail:drop()
            end
        LUA);
    }
}
class InitializeData extends Migration
{
    public function afterSchema(Mapper $mapper)
}
$mapper = $container->get(Mapper::class);

// also migrate accepts migration instance, or migration class arrays
$mapper->migrate(DropLegacySpaces::class, InitializeData::class);
``

## Performance
We can calculate mapper overhead using getInstance method that is called per each instance.\
If you don't use cache, there is single schema fetch on connection and each time schema is upgraded.\
Perfomance test was made on (AMD Ryzen 5 3600X), Ubuntu 23.10  using PHP 8.3.6

| Instance type | Instances per second |
| --- | --- |
| constructor | 4 664 172 |
| properties | 4 328 442 |
| simple array | 11 983 040 |

