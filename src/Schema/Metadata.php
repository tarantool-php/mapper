<?php

namespace Tarantool\Mapper\Schema;

use Tarantool\Mapper\Contracts;
use LogicException;

class Metadata implements Contracts\Metadata
{
    protected $manager;
    protected $types = [];

    public function __construct(Contracts\Manager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * @return Type
     */
    public function get($type)
    {
        if (!array_key_exists($type, $this->types)) {
            if (!$this->manager->getSchema()->hasSpace($type)) {
                throw new LogicException("Type $type not exists");
            }

            $fields = [];
            if ($type != 'mapping') {
                $mapping = $this->manager->get('mapping')->bySpace($type);
                foreach ($mapping as $row) {
                    $fields[$row->line] = $row->property;
                }
                ksort($fields);
            }

            $this->types[$type] = new Type($this->manager, $type, array_values($fields));
        }
        return $this->types[$type];
    }

    /**
     * @return Type
     */
    public function create($type, array $fields = null)
    {
        if ($this->manager->getSchema()->hasSpace($type)) {
            throw new LogicException("Type $type exists");
        }

        $this->manager->getSchema()->createSpace($type);

        $instance = new Type($this->manager, $type);

        $instance->addProperty('id');
        $instance->addIndex('id');

        if ($fields) {
            call_user_func_array([$instance, 'addProperty'], (array) $fields);
        }

        return $this->types[$type] = $instance;
    }
}
