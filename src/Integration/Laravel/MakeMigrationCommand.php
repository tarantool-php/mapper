<?php

namespace Tarantool\Mapper\Integration\Laravel;

use Illuminate\Console\Command;
use Illuminate\Support\Composer;

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
    public function handle(Convention $convention, Composer $composer)
    {
        $name = $this->argument('name');
        $class = studly_case($name);

        if(class_exists($class)) {
            throw new \Exception("Class $class exists");
        }

        $template = $convention->migrationTemplatePath();

        $filename = $convention->migrationPath().DIRECTORY_SEPARATOR.date('Ymd_His_').$name.'.php';

        ob_start();
        include $template;

        file_put_contents($filename, ob_get_clean());

        $composer->dumpAutoloads();
    }
}
