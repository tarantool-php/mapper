docker-compose up -d

docker-compose exec -it mapper_php_1 bash

apt update
apt install -y git

curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin \
 && mv /usr/bin/composer.phar /usr/bin/composer

composer install -o

php App/index.php

```
Fatal error: Uncaught Exception: Repository class override in /app/src/Space.php:524
Stack trace:
#0 /app/src/Mapper.php(108): Tarantool\Mapper\Space->getRepository()
#1 /app/src/Mapper.php(45): Tarantool\Mapper\Mapper->getRepository('post')
#2 /app/App/index.php(49): Tarantool\Mapper\Mapper->create('post', Array)
#3 {main}
  thrown in /app/src/Space.php on line 524
```
