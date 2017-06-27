<?php

use Tarantool\Mapper\Plugin\Temporal;
use Carbon\Carbon;

class TemporalTest extends TestCase
{
    public function testState()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $temporal = $mapper->addPlugin(Temporal::class);
        $temporal->setActor(1);

        $mapper->getSchema()
            ->createSpace('post', [
                'id' => 'unsigned',
                'title' => 'string',
            ])
            ->addIndex('id');

        $temporal->override([
            'post'  => 1,
            'begin' => '5 days ago',
            'data'  => [
                'title' => 'test post',
            ]
        ]);

        Carbon::setTestNow(Carbon::parse("+1 sec"));

        $temporal->override([
            'post'  => 1,
            'begin' => 'yesterday',
            'end'   => '+2 days',
            'data'  => [
                'title' => 'hello world',
            ]
        ]);

        $this->assertCount(0, $temporal->getState('post', 1, '1 year ago'));

        foreach (['5 days ago', '-2 days', '+2 days', '+1 year'] as $time) {
            $state = $temporal->getState('post', 1, $time);
            $this->assertNotNull('title', $state);
            $this->assertSame($state['title'], 'test post', "Validation: $time");
        }

        foreach (['midnight', 'tomorrow'] as $time) {
            $state = $temporal->getState('post', 1, $time);
            $this->assertArrayHasKey('title', $state);
            $this->assertSame($state['title'], 'hello world', "Validation: $time");
        }

        Carbon::setTestNow(Carbon::parse("+2 sec"));
        $temporal->override([
            'post' => 1,
            'begin' => '+1 day',
            'end' => '+4 days',
            'data' => [
                'title'  => 'new title',
                'notice' => 'my precious'
            ]
        ]);

        foreach (['5 days ago', '-2 days', '+3 year', '+4 days'] as $time) {
            $state = $temporal->getState('post', 1, $time);
            $this->assertNotNull('title', $state);
            $this->assertSame($state['title'], 'test post', "Validation: $time");
        }

        foreach (['+1 day', '+2 days', '+3 days', '+4 days -1 sec'] as $time) {
            $state = $temporal->getState('post', 1, $time);
            $this->assertNotNull('title', $state);
            $this->assertSame($state['title'], 'new title', "Validation: $time");
            $this->assertSame($state['notice'], 'my precious', "Validation: $time");
        }

        foreach (['midnight', 'tomorrow'] as $time) {
            $state = $temporal->getState('post', 1, $time);
            $this->assertArrayHasKey('title', $state);
            $this->assertSame($state['title'], 'hello world', "Validation: $time");
        }
    }
}
