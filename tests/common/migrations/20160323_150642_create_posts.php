<?php

use Tarantool\Mapper\Contracts\Manager;
use Tarantool\Mapper\Contracts\Migration;

class CreatePosts implements Migration
{
    public function migrate(Manager $manager)
    {
        $posts = $manager->getMeta()->make('posts');
        $posts->addProperty('body');
        $posts->addProperty('slug', 'title');
        $posts->addIndex('slug');

        $posts->addProperty(['author', 'month']);
        $posts->addIndex(['author', 'month'], ['unique' => false]);
    }
}
