<?php

namespace Entity;

use Tarantool\Mapper\Entity;

class LegacyType extends Entity
{
    /**
     * @var integer
     */
    public $id;

    /**
     * @var string
     * @required
     */
    public $class;

    /**
     * @var string
     * @required
     */
    public $name;
}
