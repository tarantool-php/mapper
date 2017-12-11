<?php

namespace Entity;

use Tarantool\Mapper\Entity as MapperEntity;

class CamelChild extends MapperEntity
{
    /**
     * @var integer
     */
    public $id;

    /**
     * @var CamelParent
     */
    public $camelParent;

    /**
     * @var string
     */
    public $name;
}
