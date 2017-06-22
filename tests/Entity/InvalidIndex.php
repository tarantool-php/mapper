<?php

namespace Entity;

use Tarantool\Mapper\Entity as MapperEntity;

class InvalidIndex extends MapperEntity
{
    /**
     * @var integer
     */
    public $id;

    /**
     * @var string
     */
    public $name;
}
