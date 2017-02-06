# Tarantool Mapper
[![License](https://poser.pugx.org/tarantool/mapper/license.png)](https://packagist.org/packages/tarantool/mapper)
[![Build Status](https://travis-ci.org/tarantool-php/mapper.svg?branch=master)](https://travis-ci.org/tarantool-php/mapper)
[![Latest Version](https://img.shields.io/github/release/tarantool-php/mapper.svg?style=flat-square)](https://github.com/tarantool-php/mapper/releases)
[![Total Downloads](https://img.shields.io/packagist/dt/tarantool/mapper.svg?style=flat-square)](https://packagist.org/packages/tarantool/mapper)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/tarantool-php/mapper/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/tarantool-php/mapper/?branch=master)
[![Code Coverage](https://scrutinizer-ci.com/g/tarantool-php/mapper/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/tarantool-php/mapper/?branch=master)

Install using composer.
```json
{
  "require": {
    "tarantool/mapper": "*"
  }
}
```

# Instantiate manager
Usually, you manage dependencies in your service provider.
To get started you should instantiate connection, packer, client and manager itself.
In this example we use PurePacker and StreamConnection. It means you don't need any pecl extensions. To see other implementations please check [client documentation](https://github.com/tarantool-php/client#usage)

```php
use Tarantool\Client\Client;
use Tarantool\Client\Connection\StreamConnection;
use Tarantool\Client\Packer\PurePacker;
use Tarantool\Mapper\Manager;

$connection = new StreamConnection();
$client = new Client($connection, new PurePacker());
$manager = new Manager($client);
```

# Logging
By default, client does not logs tarantool requests, you can use mapper\client that supports logging.
```php
use Tarantool\Client\Connection\StreamConnection;
use Tarantool\Client\Packer\PurePacker;
use Tarantool\Mapper\Manager;
use Tarantool\Mapper\Client;

$connection = new StreamConnection();
$client = new Client($connection, new PurePacker());
$manager = new Manager($client);

$result = $client->ping();

$log = $client->getLog(); // [connection event, ping request]
$log[0]->render($manager); // Make connection
$log[0]->getTime(); // event timing
$log[0]->getResponse(); // get raw response
```

# Describe entities
To get started you should describe your types and fields using meta object.
```php
// create person type, id property will be created too.
$person = $manager->getMeta()->create('person', ['name']);

// add properties on the fly
$person->addProperty('birthday');

// set properties type
$person->setPropertyType('birthday', 'integer');

// add indexes
$person->addIndex(['name'], ['unique' => false]);

// relate types (add person property with integer type)
$post = $manager->getMeta()->create('post', ['title', 'date', $person]);
```

# Working with the data
Now you can store and retreive data from tarantool storage using manager object.
```php
// get repository instance
$persons = $manager->get('person');
// create new entity
$dmitry = $persons->create(['name' => 'Dmitry']);
// save
$manager->save($dmitry);
// now you have new row in person space.
echo $dmitry->id; // 1

// you can create entities using manager wrapper.
// this way entity will be created and saved in the storage
$vasily = $manager->create('person', ['name' => 'Vasily']);

// manager cast data types and relate values with properties
// in this example there is only one string field so it will be using
$ilya = $manager->create('person', 'Ilya');

// relation casting works same way
$helloWorld = $manager->create('post', ['Hello world', $ilya]);
echo $helloWorld->person; // 3

// you can retreive entites by id from repository
$helloWorld = $manager->get('post')->find(3);

// or using manager wrapper
$helloWorld = $manager->get('post', 3);

// updates are easy
$helloWorld->title = "Hello World!";
$manager->save($helloWorld);
```
# Indexes
Indexes help us to find data as fast as possible.
```php
$note = $manager->getMeta()->create('note', ['slug', 'title', 'status']);
$note->addIndex(['slug']);
$note->addIndex(['status'], ['unique' => false]);

// find using repository
$manager->get('note')->find(['status' => 'active']);

// find using magic methods
$manager->get('note')->byStatus('active');

// find first
$manager->get('note')->oneBySlug('my-secret-note');

// find using wrapper
$manager->get('note', ['status' => 'active'];

// composite indexes can be used partial
$person = $manager->getMeta()->create('person', ['client', 'sector', 'name']);
$person->addIndex(['client', 'sector'], ['unique' => false]);

// using magic methods
$manager->get('person')->byClient(1);
$manager->get('person')->byClientAndSector(1, 5);

// using finder
$manager->get('person')->find(['client' => 1, 'sector' => 5]);
```

# Relations
You can add as much relations as you want, relations can be named
```php
$meta = $manager->getMeta();
$person = $meta->get('person');
$meta->create('article', ['title', ''author' => $person, 'reviewer' => $person]);

// create entity
$manager->create('article', ['How to eat fried worms', 'author' => $ilya, 'reviewer' => $dmitry]);
```

# Migrations
To manage your schema you can use migrations. Migration should implement Tarantool\Mapper\Contracts\Migration interface:
```php

use Tarantool\Mapper\Contracts\Manager;
use Tarantool\Mapper\Contracts\Migration;

class AddAuthentication implements Migration
{
  public function migrate(Manager $manager)
  {
    $manager->getMeta()->create('auth', ['login', 'password']);
  }
}
```
To get Migrations applied use Migrator class.
Each Migration is applied once.
```php
use Tarantool\Mapper\Migrations\Migrator;

$migrator = new Migrator();
$migrator->registerMigration(AddAuthentication::class); // instances can be registered too.
$migrator->migrate($manager);
```

# Entities classes
By default, Tarantool\Mapper\Entity class is used for all entities. You can override it and extend entity instance with busines logic methods.
```php
use Tarantool\Mapper\Entity;

class Post extend Entity
{
  public function getPreview()
  {
    return substr($this->body, 0, 100);
  }
}
```
Let manager know that you have class for post entities:
```php
$manager->getMeta()->get('post')->setEntityClass(Post::class);
echo $manager->get('post', 1)->getPreview();
```

# Array properties
You can store arrays as property without any serialization to string.
```php
$manager->getMeta()->create('shift_pattern', ['title', 'pattern']);
$manager->create('shift_pattern', [
  'title' => '5 days week',
  'pattern' => [
    ['work' => true],
    ['work' => true],
    ['work' => true],
    ['work' => true],
    ['work' => true],
    ['work' => false],
    ['work' => false],
  ]
]);

$manager->get('shift_pattern', 1)->pattern[5]; // read element with index 5 from pattern array
```

# Internals
Mapper uses IdentityMap and query caching
```php
$dmitry = $manager->get('person')->findOne(['name' => 'Dmitry']); // person with id 1
echo $dmitry == $manager->get('person', 1); // true

// query result are cached until you create new entites
$manager->get('person')->findOne(['name' => 'Dmitry']);

// you can flush cache manually
$manager->get('person')->flushCache();
```

# Using Lua
You can write entity queries in Lua:
```php
$person = $manager->getMeta()->create('person', ['name']);
$relation = $manager->getMeta()->create('person_sector', ['sector', $person]);
$relation->setPropertyType('sector', 'integer');
$relation->addIndex('sector', ['unique' => false]);

// get persons that are related with sector #27
// person_sector space have three properties - id, sector, person.
// so we use third value as person id and select person
$persons = $manager->get('person')->evaluate('
  local result = {}
  for _, link in box.space.person_sector.index.sector:pairs(27) do
    table.insert(result, box.space.person:get(link[3]))
  end
  return result
');
```
