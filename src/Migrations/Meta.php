<?php

namespace Tarantool\Mapper\Migrations;

use Tarantool\Mapper\Contracts;

class Meta implements Contracts\Migration
{
    public function migrate(Contracts\Manager $manager)
    {
        if (!$manager->getSchema()->hasSpace('meta')) {
            $migration = new Bootstrap();
            $migration->migrate($manager);
        }

        $reference = $manager->getMeta()->make('reference', ['space', 'property', 'type'])
            ->addIndex(['space'], ['unique' => false, 'parts' => [2, 'NUM']])
            ->addIndex(['type'], ['unique' => false])
            ->addIndex(['space', 'property'], ['parts' => [2, 'NUM', 3, 'STR']]);
    }
}
