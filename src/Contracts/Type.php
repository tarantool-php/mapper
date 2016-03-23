<?php

namespace Tarantool\Mapper\Contracts;

interface Type
{
    public function getManager();
    public function getName();
    public function getMapping();
    public function getSpace();

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
