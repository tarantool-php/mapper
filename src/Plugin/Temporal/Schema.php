<?php

namespace Tarantool\Mapper\Plugin\Temporal;

use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Mapper;
use InvalidArgumentException;

class Schema
{
    private $mapper;

    public function __construct(Mapper $mapper)
    {
        $this->mapper = $mapper;
    }

    public function init($name)
    {
        if (!$name) {
            throw new InvalidArgumentException("Nothing to initialize");
        }

        $method = 'init'.ucfirst($name);
        if (!method_exists($this, $method)) {
            throw new InvalidArgumentException("No initializer for $name");
        }

        $this->$method();
    }

    private function initLink()
    {
        $this->mapper->getSchema()->once(__CLASS__.'@link', function (Mapper $mapper) {
            $mapper->getSchema()
                ->createSpace('_temporal_link', [
                    'id'        => 'unsigned',
                    'parent'    => 'unsigned',
                    'entity'    => 'unsigned',
                    'entityId'  => 'unsigned',
                    'begin'     => 'unsigned',
                    'end'       => 'unsigned',
                    'timestamp' => 'unsigned',
                    'actor'     => 'unsigned',
                    'data'      => '*',
                ])
                ->addIndex(['id'])
                ->addIndex(['entity', 'entityId', 'parent', 'begin', 'timestamp', 'actor'])
                ->addIndex([
                    'fields' => 'parent',
                    'unique' => false,
                ]);

            $mapper->getSchema()
                ->createSpace('_temporal_link_aggregate', [
                    'entity' => 'unsigned',
                    'id'     => 'unsigned',
                    'begin'  => 'unsigned',
                    'end'    => 'unsigned',
                    'data'   => '*',
                ])
                ->addIndex(['entity', 'id', 'begin']);
        });

        $this->mapper->getSchema()->once(__CLASS__.'@link-idle', function (Mapper $mapper) {
            $mapper->getSchema()->getSpace('_temporal_link')->addProperty('idle', 'unsigned');
        });
    }

    private function initOverride()
    {
        $this->mapper->getSchema()->once(__CLASS__.'@states', function (Mapper $mapper) {
            $mapper->getSchema()
                ->createSpace('_temporal_override', [
                    'entity'     => 'unsigned',
                    'id'         => 'unsigned',
                    'begin'      => 'unsigned',
                    'end'        => 'unsigned',
                    'timestamp'  => 'unsigned',
                    'actor'      => 'unsigned',
                    'data'       => '*',
                ])
                ->addIndex(['entity', 'id', 'begin', 'timestamp', 'actor']);

            $mapper->getSchema()
                ->createSpace('_temporal_override_aggregate', [
                    'entity'     => 'unsigned',
                    'id'         => 'unsigned',
                    'begin'      => 'unsigned',
                    'end'        => 'unsigned',
                    'data'       => '*',
                ])
                ->addIndex(['entity', 'id', 'begin']);
        });

        $this->mapper->getSchema()->once(__CLASS__.'@override-idle', function (Mapper $mapper) {
            $mapper->getSchema()->getSpace('_temporal_override')->addProperty('idle', 'unsigned');
        });
    }
}
