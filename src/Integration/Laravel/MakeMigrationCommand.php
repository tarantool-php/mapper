<?php

namespace Tarantool\Mapper\Integration\Laravel;

use Illuminate\Console\Command;

class MakeMigrationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:migration {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new migration';

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
    public function handle()
    {
        $name = $this->argument('name');
        $class = studly_case($name);

        $template = implode(DIRECTORY_SEPARATOR, [
            dirname(dirname(dirname(__DIR__))),
            'resources', 'views', 'migration.php',
        ]);

        $filename = resource_path('migrations/'.date('Ymd_His_').$name.'.php');

        ob_start();
        include $template;

        file_put_contents($filename, ob_get_clean());
    }
}
