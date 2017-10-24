<?php

use Tarantool\Mapper\Client;
use Tarantool\Mapper\Mapper;
use Tarantool\Mapper\Schema;
use Tarantool\Mapper\Plugin\Sequence;

class PerformanceTest extends TestCase
{
    public $counter = 1000;

    public function test()
    {
        if (extension_loaded('xdebug')) {
            $this->markTestSkipped("Disable performance metrics with xdebug");
        }
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->getSchema()
            ->createSpace('tester', [
                'id' => 'unsigned',
                'text' => 'string',
            ])
            ->addIndex('id');


        $this->exec('create', 1000, function (Mapper $mapper) {
            foreach (range(1, $this->counter) as $id) {
                $mapper->create('tester', ['id' => $id, 'text' => "text for $id"]);
            }
        });

        $this->exec('read ony by one', 10000, function (Mapper $mapper) {
            foreach (range(1, $this->counter) as $id) {
                $mapper->findOne('tester', ['id' => $id]);
            }
        });

        $this->exec('mass read', 100000, function (Mapper $mapper) {
            $mapper->find('tester');
        });
    }

    private function exec($label, $value, callable $runner)
    {
        $startTime = microtime(1);

        $mapper = $this->createMapper();
        $mapper->getClient()->setLogging(true);
        $runner($mapper);

        $totalTime = microtime(1) - $startTime;

        $cleanTime = $totalTime - $mapper->getClient()->getTimeSummary();
        if ($cleanTime <= 0) {
            return [$label, [$totalTime, $mapper->getClient()->getTimeSummary()]];
        }

        $mappingPerSecond = $this->counter / $cleanTime;
        $this->assertGreaterThan($value, $mappingPerSecond, "exec: $label");

        // output overhead in milliseconds per entity
        // var_dump($label.": ".(1000 * $cleanTime / $this->counter). ' ' .$cleanTime. ' '.$mappingPerSecond);
    }
}
