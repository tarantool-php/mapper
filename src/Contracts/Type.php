<?php

namespace Tarantool\Mapper\Contracts;

interface Type
{
    public function getManager();
    public function getName();
    public function getSpaceId();
    public function getSpace();

    /**
     * @param $property string
     *
     * @return Type
     */
    public function addProperty($property);
    public function hasProperty($property);
    public function getProperties();
    public function getRequiredProperties();

    public function getPropertyType($property);
    public function setPropertyType($property, $type);

    public function getTuple($array);
    public function fromTuple($array);
    public function encodeProperty($name, $value);
    public function decodeProperty($name, $value);

    /**
     * @param $field string|array
     *
     * @return Type
     */
    public function addIndex($field, array $arguments = null);
    public function findIndex($fields);
    public function getIndex($index);
    public function getIndexTuple($index, $params);

    /**
     * @param $type Type
     * @param $property string
     *
     * @return Type
     */
    public function reference(Type $foreign, $property = null);
    public function isReference($name);
    public function getReferenceProperty(Type $foreign);
    public function getReferences();
}
