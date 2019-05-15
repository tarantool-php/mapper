<?php

use Tarantool\Mapper\Mapper;
use Tarantool\Mapper\Middleware\DebuggerMiddleware;
use Tarantool\Mapper\Plugin\Sequence;

class PerformanceTest extends TestCase
{
    public $counter = 1000;

    public function test()
    {
        if (getenv('SKIP_PERFORMANCE_TEST')) {
            $this->markTestSkipped("Disable performance metrics with xdebug");
        }

        echo PHP_EOL;

        foreach ([1, 10, 100, 1000, 10000] as $goal) {

            $mapper = $this->createMapper();
            $this->clean($mapper);

            $mapper->getSchema()
                ->createSpace('tester', [
                    'id' => 'unsigned',
                    'text' => 'string',
                ])
                ->addIndex('id');

            $mapper->setClient($mapper->getClient()->withMiddleware($this->debugger = new DebuggerMiddleware));

            $this->score('create one', $goal, function () use ($mapper, $goal) {
                foreach (range(1, $goal) as $id) {
                    $mapper->create('tester', ['id' => $id, 'text' => "text for $id"]);
                }
            });
    
            $this->score('single read', $goal, function () use ($mapper, $goal) {
                foreach (range(1, $goal) as $id) {
                    $mapper->findOne('tester', ['id' => $id]);
                }
            });
    
            $this->score('mass read', $goal, function () use ($mapper) {
                $mapper->find('tester');
            });
        }
    }

    private function score($label, $goal, callable $runner)
    {
        $startTime = microtime(1);
        $runner();
        $totalTime = microtime(1) - $startTime;

        $mapperTime = 0;
        foreach ($this->debugger->getLog() as $item) {
            $mapperTime += $item['timing'];
        }
        $this->debugger->flush();

        $cleanTime = $totalTime - $mapperTime;

        if ($cleanTime <= 0) {
            return [$label, [$totalTime, $mapperTime]];
        }

        $mappingPerSecond = $goal / $cleanTime;

        // output overhead in milliseconds
        echo implode("\t", [
            $label,
            $goal,
            1000 * $cleanTime / $goal,
            $cleanTime,
            $mapperTime,
            $totalTime,
        ]);

        echo PHP_EOL;
    }

    protected function getTimeSummary()
    {
    }
}
