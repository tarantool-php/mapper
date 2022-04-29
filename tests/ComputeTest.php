<?php

namespace Tarantool\Mapper\Tests;

use Exception;
use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Plugin\Annotation;
use Tarantool\Mapper\Plugin\Compute;
use Tarantool\Mapper\Plugin\Sequence;

class ComputeTest extends TestCase
{
    public function testAnnotation()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);
        $mapper->getPlugin(Sequence::class);

        $annotation = $mapper->getPlugin(Annotation::class);
        $annotation->register('Entity\\Address');
        $annotation->register('Entity\\AddressView');
        $annotation->migrate();

        $mapper->create('address', [
            'country' => 'Russia',
            'city' => 'Obninsk',
            'street' => 'Lenina',
            'house' => 139
        ]);

        $views = $mapper->find('address_view');
        $this->assertCount(1, $views);
        $this->assertSame($views[0]->address, 'Russia, Obninsk, Lenina 139');
    }

    public function testPhpConverter()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);
        $mapper->getPlugin(Sequence::class);

        $compute = $mapper->getPlugin(Compute::class);

        $mapper->getSchema()
            ->createSpace('person', [
                'id' => 'unsigned',
                'firstname' => 'string',
                'lastname' => 'string',
            ])
            ->createIndex('id');

        $mapper->getSchema()
            ->createSpace('person_presenter', [
                'id' => 'unsigned',
                'name' => 'string',
            ])
            ->createIndex('id');

        $compute->register('person', 'person_presenter', function (Entity $entity) {
            return [
                'name' => $entity->firstname . ' ' . $entity->lastname . '!',
            ];
        });

        $person = $mapper->create('person', [
            'firstname' => 'Dmitry',
            'lastname' => 'Krokhin',
        ]);

        $this->assertCount(1, $mapper->find('person_presenter'));
        $this->assertEquals(get_object_vars($mapper->findOne('person_presenter', $person->id)), [
            'id' => $person->id,
            'name' => 'Dmitry Krokhin!',
        ]);

        $person->firstname = 'Valery';
        $person->save();
        $this->assertCount(1, $mapper->find('person_presenter'));
        $this->assertEquals(get_object_vars($mapper->findOne('person_presenter', $person->id)), [
            'id' => $person->id,
            'name' => 'Valery Krokhin!',
        ]);

        $mapper->remove($person);

        $this->assertCount(0, $mapper->find('person_presenter'));

        $this->expectException(Exception::class);
        $mapper->create('person_presenter', ['name' => 'tester']);
    }

    public function testPhpConverterUpgrade()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);
        $mapper->getPlugin(Sequence::class);

        $compute = $mapper->getPlugin(Compute::class);

        $mapper->getSchema()->createSpace('person', [
                'id' => 'unsigned',
                'firstname' => 'string',
                'lastname' => 'string',
            ])
            ->createIndex('id');

        $person = $mapper->create('person', [
            'firstname' => 'Dmitry',
            'lastname' => 'Krokhin',
        ]);

        $mapper->getSchema()->createSpace('person_presenter', [
                'id' => 'unsigned',
                'name' => 'string',
            ])
            ->createIndex('id');

        $compute->register('person', 'person_presenter', function (Entity $entity) {
            return [
                'id' => $entity->id,
                'name' => $entity->firstname.' '.$entity->lastname.'!',
            ];
        });

        $this->assertCount(1, $mapper->find('person_presenter'));
        $this->assertEquals(get_object_vars($mapper->findOne('person_presenter', $person->id)), [
            'id' => $person->id,
            'name' => 'Dmitry Krokhin!',
        ]);
    }
}
