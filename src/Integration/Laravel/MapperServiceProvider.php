<?php

namespace Tarantool\Mapper\Integration\Laravel;

use Illuminate\Support\ServiceProvider;
use Symfony\Component\Finder\Finder;
use Tarantool\Connection\Connection;
use Tarantool\Connection\SocketConnection;
use Tarantool\Mapper\Migrations\Collection;
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

        if(is_dir(resource_path('migrations'))) {

            $migrations = [];
            foreach (with(new Finder())->files()->in(resource_path('migrations')) as $file) {
                $migrations[] = studly_case(substr($file->getBasename('.php'), 16));
            }

            $this->app->resolving(Collection::class, function(Collection $collection) use ($migrations) {
                foreach($migrations as $migration) {
                    $collection->registerMigration($migration);
                }
            });
        }
    }
}
