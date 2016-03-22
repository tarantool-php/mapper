<?php

namespace Tarantool\Mapper\Integration\Laravel;

use Illuminate\Console\Command;
use Tarantool\Mapper\Manager;
use Tarantool\Mapper\Migrations\Collection;

class MigrateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Apply all migration to the schema';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle(Manager $manager, Collection $collection)
    {
        $collection->migrate($manager);
    }
}
