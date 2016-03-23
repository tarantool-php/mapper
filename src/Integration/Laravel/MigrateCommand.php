<?php

namespace Tarantool\Mapper\Integration\Laravel;

use Illuminate\Console\Command;
use Tarantool\Mapper\Manager;
use Tarantool\Mapper\Migrations\Migrator;

class MigrateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Apply all migration to the schema';

    /**
     * Create a new command instance.
     */
    public function __construct(Convention $convention)
    {
        $this->signature = $convention->migrateSignature();
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle(Manager $manager, Migrator $migrator)
    {
        $migrator->migrate($manager);
    }
}
