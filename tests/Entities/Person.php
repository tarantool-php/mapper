<?php

namespace Entities;

use Tarantool\Mapper\Entity;

class Person extends Entity
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

