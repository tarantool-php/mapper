<?php

namespace Tarantool\Mapper\Integration\Laravel;

use Illuminate\Console\Command;
use Tarantool\Mapper\Manager;
use Tarantool\Mapper\Migrations\Migrator;
use Tarantool\Schema\Space;
use Tarantool\Schema\Index;

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
    protected $description = 'Flush database and apply all migrations';

    /**
     * Create a new command instance.
     */
    public function __construct(Convention $convention)
    {
        $this->signature = $convention->refreshSignature();
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle(Manager $manager, Migrator $migrator)
    {
        $client = $manager->getClient();
        $schema = new Space($client, Space::VSPACE);
        $response = $schema->select([], Index::SPACE_NAME);
        $data = $response->getData();
        foreach ($data as $row) {
            if ($row[1] == 0) {
                // user space
                $client->evaluate('box.schema.space.drop('.$row[0].')');
            }
        }

        $migrator->migrate($manager);
    }
}
