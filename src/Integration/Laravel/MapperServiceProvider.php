<?php

namespace Tarantool\Mapper\Integration\Laravel;

use Illuminate\Support\ServiceProvider;
use Symfony\Component\Finder\Finder;
use Tarantool\Connection\Connection;
use Tarantool\Connection\SocketConnection;
use Tarantool\Mapper\Migrations\Migrator;
use Tarantool\Packer\Packer;
use Tarantool\Packer\PurePacker;
use UnexpectedValueException;

class MapperServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        //
    }

    /**
     * Register the application services.
     */
    public function register()
    {
        $convention = $this->app->make(Convention::class);

        $config = config('tarantool');

        switch ($config['connection']) {
            case 'pure':
                $this->app->singleton(Connection::class, function ($app) {
                    return new SocketConnection(config('tarantool.host'), config('tarantool.port'));
                });
                break;
            default:
                throw new UnexpectedValueException('tarantool.connection = '.$config['connection']);
        }

        switch ($config['msgpack']) {
            case 'pure':
                $this->app->singleton(Packer::class, PurePacker::class);
                break;
            default:
                throw new UnexpectedValueException('tarantool.msgpack = '.$config['msgpack']);
        }

        $this->app->singleton(Manager::class);

        if (is_dir($convention->migrationPath())) {
            $this->app->resolving(Migrator::class, function (Migrator $migrator) use ($convention) {

                $migrations = [];
                foreach (with(new Finder())->files()->in($convention->migrationPath()) as $file) {
                    $migrations[] = studly_case(substr($file->getBasename('.php'), 16));
                }

                foreach ($migrations as $migration) {
                    $migrator->registerMigration($migration);
                }
            });
        }
    }
}
