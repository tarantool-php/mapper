<?php

namespace Entity;

use Tarantool\Mapper\Entity as Entity;

class Address extends Entity
{
    /**
     * @var integer
     */
    public $id;

    /**
     * @var string
     */
    public $country;

    /**
     * @var string
     */
    public $city;

    /**
     * @var string
     */
    public $street;

    /**
     * @var integer
     */
    public $house;
}
