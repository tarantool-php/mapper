<?php

use Tarantool\Mapper\Migrations\Migrator;
use Tarantool\Mapper\Integration\Laravel\Convention;

class LaravelTest extends PHPUnit_Framework_TestCase
{
    public function testConvention()
    {
        $convention = new Convention;

        // default signatures
        $this->assertSame('migrate', $convention->migrateSignature());
        $this->assertSame('make:migration {name}', $convention->makeMigrationSignature());

        $this->assertSame(
            $convention->migrationPath(),
            implode(DIRECTORY_SEPARATOR, [__DIR__, 'common', 'migrations'])
        );

        $this->assertSame(
            resource_path('migrations'),
            implode(DIRECTORY_SEPARATOR, [__DIR__, 'common', 'migrations'])
        );

        $this->assertSame(
            $convention->migrationTemplatePath(),
            implode(DIRECTORY_SEPARATOR, [dirname(__DIR__), 'resources', 'views', 'migration.php'])
        );
    }
}
