<?php

namespace Tarantool\Mapper\Schema;

use Tarantool\Mapper\Contracts;
use LogicException;

class Meta implements Contracts\Meta
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
            $references = null;
            if ($type != 'mapping') {
                $mapping = $this->manager->get('mapping')->find(['space' => $type]);
                foreach ($mapping as $row) {
                    $fields[$row->line] = $row->property;
                }
                ksort($fields);
                if (!in_array($type, ['reference', 'sequences'])) {
                    $references = $this->manager->get('reference')->find(['space' => $type]);
                }
            }
            $this->types[$type] = new Type($this->manager, $type, array_values($fields), $references);
        }

        return $this->types[$type];
    }

    /**
     * @return Type
     */
    public function make($type, array $fields = null)
    {
        if ($this->manager->getSchema()->hasSpace($type)) {
            throw new LogicException("Type $type exists");
        }

        $this->manager->getSchema()->makeSpace($type);

        $instance = new Type($this->manager, $type);

        $instance->addProperty('id');
        $instance->addIndex('id');

        if ($fields) {
            foreach ($fields as $field) {
                if ($field instanceof Contracts\Type) {
                    $instance->reference($field);
                } else {
                    $instance->addProperty($field);
                }
            }
        }

        return $this->types[$type] = $instance;
    }
}
