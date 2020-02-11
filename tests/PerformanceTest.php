<?php

use Tarantool\Mapper\Mapper;
use Tarantool\Client\Middleware\LoggingMiddleware;
use Tarantool\Mapper\Plugin\Sequence;
use Psr\Log\AbstractLogger;

class PerformanceTest extends TestCase
{
    public $counter = 1000;

    public function test()
    {
        if (getenv('SKIP_PERFORMANCE_TEST') !== "") {
            $this->markTestSkipped("SKIP_PERFORMANCE_TEST = " . getenv('SKIP_PERFORMANCE_TEST'));
        }

        echo PHP_EOL;

        $this->logger = new class extends AbstractLogger
        {
            public $logs = [];
            public function log($level, $message, array $context = [])
            {
                $this->logs[] = compact('level', 'message', 'context');
            }
        };

        foreach ([1, 10, 100, 1000, 10000] as $goal) {

            $mapper = $this->createMapper();
            $this->clean($mapper);

            $mapper->getSchema()
                ->createSpace('tester', [
                    'id' => 'unsigned',
                    'text' => 'string',
                ])
                ->addIndex('id');

                $mapper = new Mapper($mapper->getClient()->withMiddleware(new LoggingMiddleware($this->logger)));

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
        foreach ($this->logger->logs as $item) {
            if (array_key_exists('duration_ms', $item['context'])) {
                $mapperTime += $item['context']['duration_ms'];
            }
        }
        $this->logger->logs = [];

        $row = [
            $label,
            $goal,
            number_format($totalTime, 3),
            str_pad(number_format($goal / ($totalTime), 0, '.', ''), 7, ' ', STR_PAD_LEFT),
        ];

        $cleanTime = $totalTime - $mapperTime;

        if ($cleanTime > 0) {
            $row[] = number_format(1000 * $cleanTime, 3);
            $row[] = number_format(1000 * $cleanTime / $goal, 3);
            $row[] = number_format($cleanTime, 3);
            $row[] = number_format($mapperTime, 3);
        }

        echo implode("\t", $row), PHP_EOL;
    }

    protected function getTimeSummary()
    {
    }
}
