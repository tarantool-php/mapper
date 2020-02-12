docker-compose up -d

docker-compose exec -it mapper_php_1 bash

apt update
apt install -y git

curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin \
 && mv /usr/bin/composer.phar /usr/bin/composer

composer install -o

php App/index.php

unix/:/var/run/tarantool/tarantool.sock> box.space.post:format()
---
- [{'type': 'unsigned', 'name': 'id', 'is_nullable': false}, {'type': 'string', 'name': 'title',
    'is_nullable': true}]
...

unix/:/var/run/tarantool/tarantool.sock> box.space.post:select()
---
- - [4, '1']
...
