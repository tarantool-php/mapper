# Tarantool Mapper
[![License](https://poser.pugx.org/tarantool-php/mapper/license.png)](https://packagist.org/packages/tarantool-php/mapper)
[![Build Status](https://travis-ci.org/tarantool-php/mapper.svg?branch=master)](https://travis-ci.org/tarantool-php/mapper)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/tarantool-php/mapper/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/tarantool-php/mapper/?branch=master)
[![Code Coverage](https://scrutinizer-ci.com/g/tarantool-php/mapper/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/tarantool-php/mapper/?branch=master)

Library is in development, use it at your own risk.

# Installation
Using composer.
```json
{
  "min-stability": "dev",
  "require": {
    "tarantool/mapper": "*"
  }
}
```


# Quick Example
Please, note - no PHP extensions are needed.
```php

use Tarantool\Client;
use Tarantool\Connection\SocketConnection;
use Tarantool\Mapper\Manager;
use Tarantool\Packer\PurePacker;

$client = new Client(new SocketConnection(), new PurePacker());
$manager = new Manager($client);

// describe your model
$meta = $manager->getMeta()->create('post', ['title', 'slug', 'body']);
$meta->addIndex('slug');

// write your code
$post = $manager->get('post')->make([
  'title' => 'Hello Tarantool',
  'slug' => 'first-post',
  'body' => 'It is a good way to start working with tarantool'
]);

// persist in the database
$manager->save($post);

// use indexes
$samePost = $manager->get('post')->bySlug('first-post');

// all repositories uses identity map
$post == $samePost; // true
```

# Documentation

Interesting? Please come back later and check `docs` folder.
