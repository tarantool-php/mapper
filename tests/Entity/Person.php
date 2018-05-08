<?php

namespace Entity;

use Tarantool\Mapper\Entity as MapperEntity;

class Person extends MapperEntity
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
     * @var string
     */
    public $fullName;

    public function beforeCreate()
    {
        $this->fullName = $this->name.'!';
    }
}
