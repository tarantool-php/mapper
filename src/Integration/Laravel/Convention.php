<?php

namespace Tarantool\Mapper\Integration\Laravel;

class Convention
{
    public function migrationTemplatePath()
    {
        return implode(DIRECTORY_SEPARATOR, [
            dirname(dirname(dirname(__DIR__))),
            'resources', 'views', 'migration.php',
        ]);
    }

    public function migrationPath()
    {
        return resource_path('migrations');
    }

    public function makeMigrationSignature()
    {
        return 'tnt:migration {name}';
    }

    public function migrateSignature()
    {
        return 'tnt:migrate';
    }

    public function refreshSignature()
    {
        return 'tnt:refresh';
    }
}
