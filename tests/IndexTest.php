<?php

class IndexTest extends PHPUnit_Framework_TestCase
{
    public function testSimple()
    {
        $manager = Helper::createManager();

        $manager->getMeta()
            ->create('unit_param', ['unit', 'param', 'value'])
            ->addIndex(['unit', 'param']);

        $manager->create('unit_param', [
            'unit' => '1',
            'param' => '1',
            'value' => '11',
        ]);

        $manager->create('unit_param', [
            'unit' => '1',
            'param' => '2',
            'value' => '12',
        ]);

        $manager->create('unit_param', [
            'unit' => '2',
            'param' => '1',
            'value' => '21',
        ]);

        $manager = Helper::createManager(false);
        $unitParam = $manager->get('unit_param');
        $this->assertSame($unitParam->findOne(['unit' => '1', 'param' => '2'])->value, '12');
        $this->assertSame($unitParam->findOne(['unit' => '2', 'param' => '1'])->value, '21');
    }
}
