<?php

namespace Tarantool\Mapper\Contracts;

interface Type
{
    public function getName();

    public function getMapping();

    /**
     * @param $field string|array
     * @return Type
     */
    public function addIndex($field, array $arguments = null);

    /**
     * @param $property string
     * @return Type
     */
    public function addProperty($property);

    public function encode($array);

    public function decode($array);
}
