<?php

use Tarantool\Mapper\Plugin\Temporal;
use Carbon\Carbon;

class TemporalTest extends TestCase
{
    public function testLinkLog()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $temporal = $mapper->addPlugin(Temporal::class);
        $temporal->setActor(1);

        $temporal->link([
            'begin'  => 0,
            'end'    => 0,
            'person' => 1,
            'role'   => 2,
            'sector' => 3,
        ]);

        Carbon::setTestNow(Carbon::parse("+1 sec"));

        $temporal->link([
            'begin'  => 0,
            'end'    => 0,
            'person' => 1,
            'role'   => 3,
        ]);

        Carbon::setTestNow(Carbon::parse("+2 sec"));

        $temporal->link([
            'begin'  => 0,
            'end'    => 0,
            'person' => 1,
            'sector' => 5,
        ]);

        $this->assertCount(0, $temporal->getLinksLog('person', 2));
        $this->assertCount(3, $temporal->getLinksLog('person', 1));
        $this->assertCount(2, $temporal->getLinksLog('person', 1, ['sector']));
        $this->assertCount(2, $temporal->getLinksLog('person', 1, ['role']));
        $this->assertCount(1, $temporal->getLinksLog('sector', 5));
        $this->assertCount(1, $temporal->getLinksLog('sector', 5, ['person']));
        $this->assertCount(0, $temporal->getLinksLog('sector', 5, ['role']));
    }

    public function testThreeLinks()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $temporal = $mapper->addPlugin(Temporal::class);
        $temporal->setActor(1);

        $temporal->link([
            'begin'  => 0,
            'end'    => 0,
            'person' => 1,
            'role'   => 2,
            'sector' => 3,
        ]);

        $links = $temporal->getLinks('person', 1, 'now');
        $this->assertCount(1, $links);
        $this->assertArrayNotHasKey('data', $links[0]);
    }

    public function testTwoWayLinks()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $temporal = $mapper->addPlugin(Temporal::class);
        $temporal->setActor(1);

        $temporal->link([
            'person' => 1,
            'role'   => 2,
        ]);

        $links = $temporal->getLinks('person', 1, 'now');
        $this->assertCount(1, $links);
        $this->assertArrayNotHasKey('data', $links[0]);
    }

    public function testLinks()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $temporal = $mapper->addPlugin(Temporal::class);
        $temporal->setActor(1);

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
            $this->assertArrayHasKey('title', $state, "Validation: $time");
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
