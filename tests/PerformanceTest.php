<?php

namespace Tarantool\Mapper\Tests;

use Psr\Log\AbstractLogger;
use Tarantool\Client\Middleware\LoggingMiddleware;
use Tarantool\Mapper\Mapper;
use Tarantool\Mapper\Plugin\Sequence;

class PerformanceTest extends TestCase
{
    public $counter = 1000;

    public function test()
    {
        if (getenv('SKIP_PERFORMANCE_TEST') !== "") {
            $this->markTestSkipped("SKIP_PERFORMANCE_TEST = " . getenv('SKIP_PERFORMANCE_TEST'));
        }

        echo PHP_EOL;
        $headers = [
            'Operation',
            'Counter',
            'Client time',
            'Mapper time',
            'Total time',
            'Client RPS',
            'Mapper RPS',
            'Total RPS',
        ];

        $line = array_map(fn($r) => '---', $headers);

        echo '| ', implode(' | ', $headers), ' |', PHP_EOL;
        echo '| ', implode(' | ', $line), ' |', PHP_EOL;

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

        $clientTime = 0;
        foreach ($this->logger->logs as $item) {
            if (array_key_exists('duration_ms', $item['context'])) {
                $clientTime += $item['context']['duration_ms'] / 1000;
            }
        }

        $this->logger->logs = [];

        $mapperTime = $totalTime - $clientTime;

        $row = [
            $label,
            $goal,
            number_format($clientTime, 3),
            number_format($mapperTime, 3),
            number_format($totalTime, 3),
            $clientTime ? number_format($goal / $clientTime, 3) : '∞',
            $mapperTime ? number_format($goal / $mapperTime, 3) : '∞',
            number_format($goal / $totalTime, 3),
        ];

        echo '| ', implode(" | ", $row), ' |', PHP_EOL;
    }
}
