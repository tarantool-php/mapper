<?php

namespace Entity;

use Tarantool\Mapper\Entity as MapperEntity;

class Paycode extends MapperEntity
{
    /**
     * @var integer
     */
    public $id;

    /**
     * @var string
     */
    public $name;

    /**
     * @var float
     */
    public $factor;
}
