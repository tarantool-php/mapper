<?php

use Tarantool\Mapper\Plugin\Temporal;
use Carbon\Carbon;

class TemporalTest extends TestCase
{
    public function testTwoWayLinks()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $temporal = $mapper->addPlugin(Temporal::class);
        $temporal->setActor(1);

        foreach (['person', 'role'] as $spaceName) {
            $mapper->getSchema()
                ->createSpace($spaceName, [
                    'id' => 'unsigned',
                ])
                ->addIndex('id');
        }

        $temporal->link([
            'person' => 1,
            'role'   => 2,
        ]);

        $links = $temporal->getLinks('person', 1, 'now');
        $this->assertCount(1, $links);
    }

    public function testLinks()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $temporal = $mapper->addPlugin(Temporal::class);
        $temporal->setActor(1);

        foreach (['person', 'role', 'sector'] as $spaceName) {
            $mapper->getSchema()
                ->createSpace($spaceName, [
                    'id' => 'unsigned',
                ])
                ->addIndex('id');
        }

        $temporal->link([
            'begin'  => '-1 day',
            'person' => 1,
            'role'   => 2,
            'sector' => 3,
        ]);

        $temporal->link([
            'end'    => '+2 day',
            'person' => 1,
            'role'   => 4,
            'sector' => 3,
        ]);

        $temporal->link([
            'begin'  => '-1 week',
            'end'    => '+1 week',
            'person' => 2,
            'role'   => 22,
            'sector' => 3,
            'data'   => ['superuser' => true],
        ]);

        // link data validation
        $thirdSectorLinksForToday = $temporal->getLinks('sector', 3, 'today');

        $this->assertCount(3, $thirdSectorLinksForToday);

        $superuserLink = null;
        foreach ($thirdSectorLinksForToday as $link) {
            if ($link['person'] == 2) {
                $superuserLink = $link;
            }
        }

        $this->assertNotNull($superuserLink);
        $this->assertArrayHasKey('data', $superuserLink);
        $this->assertArrayHasKey('superuser', $superuserLink['data']);
        $this->assertSame($superuserLink['data']['superuser'], true);
    }

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
